<?php

/*  params:
        pk
        fk: table
        null
        min: value or length
        max: value or length
        url, email
 */

// $model = array(
//  var => [ type, params, ... ]
// )

class Model {
    const TYPE_INT = 0;
    const TYPE_FLOAT = 1;
    const TYPE_STRING = 2;
    const TYPE_TEXT = 3;
    const TYPE_DATE = 4;
    const TYPE_FOREIGNKEY = 5;

    protected $__model, $__db;
    private $__id;

    function __construct($db) {
        $this->__db = $db;
        $this->__fklist = array();
        $this->createModel();
    }

    public function createModel() {
        foreach($this->__model as $property => $attrib_list) {
            if (current($attrib_list) == Model::TYPE_FOREIGNKEY) {
                $foreignclass = next($attrib_list);

                if (class_exists($foreignclass)) {
                    $this->$property = new $foreignclass($this->__db);
                    $this->__fklist[$property] = $foreignclass;
                }
                else
                    throw new Exception("Foreign key points to a non existing class: " . $foreignclass);
            }

            else
                $this->$property = null;
        }
    }

    public function getProp($prop) {
        if (isset($this->$prop))
            return $this->$prop;

        else
            throw new Exception("Property does not exists under model", 20);
    }

    public function setProp($prop, $value) {
        if (property_exists($this, $prop)) {
            $attrib_list = $this->__model[$prop];

            if (!in_array('null', $attrib_list) && $value == null)
                throw new Exception('Type is not coherent: cannot be NULL', 21);

            $type = array_shift($attrib_list);

            if ($type == Model::TYPE_INT) {
                $options = array();

                foreach ($attrib_list as $attrib) {
                    // keys in the format of param: value
                    $pv = explode(":", $attrib);
                    $p = current($pv); // the param
                    $v = next($pv); // the value

                    if (($p == 'min') && is_numeric($v))
                        $options['options']['min_range'] = $v;

                    else if (($p == 'max') && is_numeric($v))
                        $options['options']['max_range'] = $v;
                }

                if (!filter_var($value, FILTER_VALIDATE_INT, $options))
                    throw new Exception("Type is not coherent: INT", 22);
            }

            else if (($type == Model::TYPE_FLOAT) && !filter_var($value, FILTER_VALIDATE_FLOAT))
                throw new Exception("Type is not coherent : FLOAT", 23);

            else if ($type == Model::TYPE_STRING) {
                $options = array();

                foreach ($attrib_list as $attrib) {
                    $pv = explode(":", $attrib);
                    $p = current($pv);
                    $v = next($pv);

                    if (($p == 'min') && is_numeric($v)) {
                        if (strlen($value) < $v)
                            throw new Exception("Type is not coherent: STRING minimum lenght is " . $v, 24);
                    }

                    else if (($p == 'max') && is_numeric($v)) {
                        if (strlen($value) > $v)
                            $value = substr($value, 0, $v);
                    }

                }
            }

            else if ($type == Model::TYPE_DATE) {
                $date = DateTime::createFromFormat('d/m/Y H:i:s', $value);
                $value = $date->format('Y-m-d H:i:s');
            }

            else if ($type == Model::TYPE_FOREIGNKEY) {
                $foreignclass = current($attrib_list);

                if (class_exists($foreignclass))
                    $value = new $foreignclass($this->__db);
                else
                    throw new Exception("Foreign key points to a non existing class: " . $foreignclass);
            }

            $this->$prop = $value;
        }
    }

    public function find($cond = null) {
        $r = $this->__db->select(get_class($this), '*', $cond, 1);

        if (empty($r))
            throw new Exception('No data found', 25);

        else {
            foreach ($r as $property => $value) {
                if (array_key_exists($property, $this->__model) && (reset($this->__model[$property]) != Model::TYPE_FOREIGNKEY))
                    $this->$property = $value;
            }

            // fetch the tables referenced as foreign key
            foreach ($this->__fklist as $reference => $fk) {
                if ($r[$reference] != null) {
                    $this->$reference = new $fk($this->__db);
                    $this->$reference->findById($r[$reference]);
                }
            }

            $this->__id = $r['id'];
        }
    }

    public function findById($id) {
        if (is_numeric($id)) {
            try {
                $r = $this->find('id = ' . $id);
                $this->__id = $id;
            } catch (Exception $e) {
                throw new Exception('No data found by id ' . $id, 211);
            }

            return $r;
        }

        else
            throw new Exception('ID must be numeric', 26);
    }

    public function exists($id) {
        $class = get_class($this);
        $n = new $class($this->__db);

        try {
            $n->findById($id);
            return true;
        } catch (Exception $e) {
            return false;
        }
    }

    public function insert() {
        $data = array();
        $fkinserted = array();

        // fetch the tables referenced as foreign key
        // we insert this objects before to get their id
        foreach ($this->__fklist as $reference => $fk) {
            if ($this->$reference != null) {
                if (!$this->$reference->exists($this->$reference->getId())) {
                    $this->$reference->insert();
                    $fkinserted[$reference] = $this->__db->getLastId();
                }
                else {
                    $this->$reference->merge();
                    $fkinserted[$reference] = $this->$reference->getId();
                }

                $data[$reference] = $this->$reference->getId();
            }
        }

        // data must be set through setProp, so we trust it's coherent
        // unless no data was inserted, in which case...

        foreach ($this->__model as $property => $attrib_list) {
            if (!in_array("null", $attrib_list) && empty($this->$property))
                throw new Exception('Property cannot be null: ' . $property, 27);

            if (reset($this->__model[$property]) != Model::TYPE_FOREIGNKEY)
                $data[$property] = $this->$property;

            // if it's a foreign key, we have the id from the previous loop
            else {
                if (array_key_exists($property, $data)) {
                    $fkinserted[$reference];
                    $data[$property] = $fkinserted[$reference];
                }
                else
                    $data[$property] = null;
            }
        }

        if (is_numeric($this->__id) && ($this->__id >= 0))
            $data['id'] = $this->__id;

        $r = $this->__db->insert(get_class($this), $data);

        if (!$r)
            throw new Exception('Error inserting the new item', 28);
    }

    public function delete($id = null) {
        // can delete only if id is provided
        // or if findById was called successfully
        if (!$id && $this->__id)
            $id = $this->__id;

        if ($id)
            $this->__db->delete(get_class($this), $id);

        else
            throw new Exception('No id specified', 210);
    }

    public function merge($cond = null) {
        if (!$cond)
            $cond = 'id = ' . $this->__id;

        $data = array();
        $fkinserted = array();

        // fetch the tables referenced as foreign key
        // we insert this objects before to get their id
        foreach ($this->__fklist as $reference => $fk) {
            if ($this->$reference != null) {
                if (!$this->$reference->exists($this->$reference->getId())) {
                    $this->$reference->insert();
                    $fkinserted[$reference] = $this->__db->getLastId();
                }
                else {
                    $this->$reference->merge();
                    $fkinserted[$reference] = $this->$reference->getId();
                }

                $data[$reference] = $this->$reference->getId();
            }
        }

        // data must be set through setProp, so we trust it's coherent
        // unless no data was inserted, in which case...

        foreach ($this->__model as $property => $attrib_list) {
            if (!in_array("null", $attrib_list) && empty($this->$property))
                throw new Exception('Property cannot be null: ' . $property, 27);

            if (reset($this->__model[$property]) != Model::TYPE_FOREIGNKEY)
                $data[$property] = $this->$property;


            // if it's a foreign key, we have the id from the previous loop
            else {
                if (array_key_exists($property, $data)) {
                    $fkinserted[$reference];
                    $data[$property] = $fkinserted[$reference];
                }
                else
                    $data[$property] = null;
            }
        }

        if (is_numeric($this->__id) && ($this->__id > 0))
            $data['id'] = $this->__id;

        $r = $this->__db->update(get_class($this), $data, $cond);

        if (!$r)
            throw new Exception('Error updating this item', 29);
    }

    public function getId() {
        return $this->__id;
    }
}
