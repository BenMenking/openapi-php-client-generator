<?php

class ModelHandler {
    private $data;
    private $namespace;
    private $paths = [];
    private $models = [];

    public function __construct($data, $namespace, $paths) {
        $this->data = $data;
        $this->namespace = $namespace;
        $this->paths = $paths;
    }

    public function parse() {
        foreach($this->paths as $path) {
            foreach($this->data[$path] as $component_type=>$component_data) {
                foreach($component_data as $model_name=>$data) {
                    $model = new Model();
                    $model->namespace = $this->namespace;
                    $model->name = $this->sanitize_name($model_name);
                    
                    if( isset($data['properties']) ) {
                        foreach($data['properties'] as $prop_name=>$property_data) {
                            $prop = new ModelProperty();

                            if( isset($property_data['$ref']) ) {
                                $cmp = $this->get_component($this->data, $property_data['$ref']);

                                if( $cmp['type'] !== 'object' ) {
                                    $prop->name = $this->sanitize_name($prop_name, false);
                                    $prop->scope = 'protected';
                                    $prop->description = 'The ' . $prop_name . ' property';
                                    $prop->type = 'String';
                                }
                                else {
                                    $prop->name = $this->sanitize_name($prop_name) . 'Model';
                                    $prop->scope = 'protected';
                                    $prop->description = 'The ' . $prop->name . ' object';
                                    $prop->type = 'object';
                                    $model->uses[] = "use {$this->namespace}\\" . $prop->name . ";\n";
                                }
                            }
                            else if( isset($property_data['type']) ) {
                                $prop->name = $this->sanitize_name($prop_name, false);
                                $prop->scope = 'protected';
                                $prop->description = 'The ' . $prop_name . ' property';
                                $prop->type = $property_data['type'];
                            }
                            else if( isset($property_data['allOf']) || isset($property_data['oneOf']) || isset($property_data['anyOf'])
                                || isset($property_data['not']) ) {
                                // not sure what to do
                                continue;
                            }
                            else if( isset($property_data['type']) ) {
                                $prop->name = $this->sanitize_name($prop_name, false);
                                $prop->scope = 'protected';
                                $prop->type = 'string';
                            }
                            else {
                                echo "WARNING: No type or \$ref!\n";
                                print_r($property_data);
                                exit;
                            }

                            $model->properties[] = $prop; 
                        }                       
                    }
                    else if( isset($data['content']) ) {
                        $prop = new ModelProperty();
                        $prop->content_type = array_keys($data['content'])[0];
                        $p = explode('/', $data['content'][$prop->content_type]['schema']['$ref']);
                        $prop->name = $this->sanitize_name($p[count($p)-1]) . 'Model';
                        $prop->scope = 'protected';
                        $prop->description = 'The ' . $prop->name . ' object';
                        $prop->type = 'object';
            
                        $model->properties[] = $prop;
                    }
                    else if( isset($data['type']) ) {
                        continue;
                    }
                    else {
                        echo "Not a valid object (probably string, integer placeholder, etc.\n";
                        continue;
                    }
                    
                    $this->models[] = $model;
                }
            }
        }
    }

    public function save($path) {
        foreach($this->models as $model) {
            $code = $this->generate_code($model);

            // get last ././...../.././.
            $filespace_parts = explode('\\', $this->namespace);
            $filespace = $filespace_parts[count($filespace_parts)-1];

            $outputPath = $path . $filespace;

            @mkdir($outputPath . '/Model', 0777, true); // if it exists, don't bother with telling us
            
            file_put_contents("$outputPath/Model/{$model->name}Model.php", $code);            
        }
    }

    private function generate_code($model) {
        $document = "<?php \n\n";
        $document .= "namespace {$model->namespace}\\Model;\n\n";

        if( !empty($model->uses) ) {
            foreach($model->uses as $use) {
                $document .= $use;
            }

            $document .= "\n";
        }

        $document .= "class {$model->name}Model {\n";
        if( isset($model->properties) ) {
            if( !isset($model->properties) ) {
                $model->properties = [];
            }

            foreach($model->properties as $prop) {
                if( isset($prop->description) ) {
                    $document .= "\t/* {$prop->description} */\n";
                }

                if( !isset($prop->type) ) {
                    $prop->type = "foobar";
                }

                switch($prop->type) {
                    case "string":
                        if( isset($prop->enum) && count($prop->enum) == 1 ) {
                            $document .= "\tpublic \${$prop->name} = '{$prop->enum[0]}';\n\n";                        
                        }
                        else {
                            $document .= "\tpublic \${$prop->name} = null;\n\n";
                        }
                        break;
                    case "integer":
                        $document .= "\tpublic \${$prop->name} = null;\n\n";
                        break;
                    case "boolean":
                        $document .= "\tpublic \${$prop->name} = null;\n\n";                    
                        break;
                    case "array":
                        $document .= "\tpublic \${$prop->name} = null;\n\n";                    
                        break;
                    case "number":
                        $document .= "\tpublic \${$prop->name} = null;\n\n";                                        
                        break;
                    case "object":
                        $document .= "\tpublic \${$prop->name} = null;\n\n";                                        
                        break;
                    default:
                        $document .= "\tpublic \${$prop->name} = null;\n\n";                                        
                }

            }
        }
        else if( isset($model->type) ) {
            echo "Creating no-property class $name\n";
        }
        else {
            echo "Name: {$model->name}\n";
            print_r($model);
            die("default failed\n");
        }
    
        if( isset($model->required) && $model->required ) {
            $document .= "\tprivate \$required = ['" . implode("', '", $model->required) . "'];\n\n";
            $document .= "\tpublic function hasRequired() {\n";
            $document .= "\t\tforeach(\$this->required as \$n) {\n";
            $document .= "\t\t\tif( empty(\$this->\$n) ) return false;\n";
            $document .= "\t\t}\n";
            $document .= "\t\treturn true;\n";
            $document .= "\t}\n\n";
        }

        $document .= "\tpublic function __serialize() {\n";
        $document .= "\t\t\$vars = get_class_vars(get_class(\$this));\n\n";
        $document .= "\t\t\$list = [];\n";
        $document .= "\t\tforeach(\$vars as \$key=>\$val) {\n";
        $document .= "\t\t\tif( \$key === 'required' ) continue;\n";
        $document .= "\t\t\tif( is_object(\$this->{\$key}) ) {\n";
        $document .= "\t\t\t\t\$list[\$key] = \$this->{\$key}->__serialize();\n";
        $document .= "\t\t\t}\n";
        $document .= "\t\t\telse if( !empty(\$this->{\$key}) ) {\n";
        $document .= "\t\t\t\t\$list[\$key] = \$this->{\$key};\n";
        $document .= "\t\t\t}\n";
        $document .= "\t\t}\n";
        $document .= "\t\treturn \$list;\n";
        $document .= "\t}\n";

        $document .= "}\n\n";

        return $document;     
    }

    private function sanitize_name($name, $as_class = true) {
        $separators = [' ', '_'];

        // clena up parameter name
        foreach($separators as $sep) {
            if( strpos($name, $sep) !== false ) {
                $ft = explode($sep, $name);
                for($a = 1; $a < count($ft); $a++) {
                    $ft[$a] = ucfirst($ft[$a]);
                }
                $name = implode('', $ft);
            }
        }

        // convert periods '.' to underscores '_'
        $name = strtr($name, ['.'=>'_']);

        if( $as_class ) {
            return ucfirst($name);
        }
        else {
            return $name;
        }
    }

    protected function recurse_path($array, $parts) {
        $end = $parts[count($parts)-1];
    
        $next = array_shift($parts);
    
        if( $next !== $end ) {
            return $this->recurse_path($array[$next], $parts);
        }
        else {
            $array[$next]['name'] = $next;
            return $array[$next];
        }
    }    

    private function get_component($spec, $ref) {
        $x = explode('/', $ref);
        array_shift($x);

        return $this->recurse_path($spec, $x);
    }
}

class Model {
    public $name;
    public $namespace;
    public $properties = [];
}

class ModelProperty {
    public $name;
    public $scope;
    public $description;
}