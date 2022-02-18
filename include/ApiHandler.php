<?php

class ApiHandler {
    private $data;
    private $namespace;
    private $external_models = [];
    private $apis;

    public function __construct(Array $data, String $namespace) {
        $this->data = $data;
        $this->namespace = $namespace;
    }

    public function parse() {
        $this->apis = [];

        // get all possible tags first and create an API object for each one
        //
        foreach($this->data['paths'] as $url=>$data) {
            foreach($data as $http_method=>$method) {
                $x = explode(' ', $method['tags'][0]);
                foreach($x as &$t) {
                    $t = ucfirst($t);
                }
                $tag = implode('', $x);

                if( !isset($this->apis[$tag]) ) {
                    $a = new Api($tag);
                    // take care of some stuff up front, will make later code cleaner
                    $a->url = $url;
                    $a->namespace = $this->namespace;
                    $a->uses = [
                        "use Psr\\Http\\Client\\ClientInterface;",
                        "use Psr\\Http\\Message\\RequestInterface;",
                        "use Psr\\Http\\Message\\ResponseInterface;",
                        "use GuzzleHttp\\Client;",
                        "use GuzzleHttp\\Psr7\\Request;"
                    ]; 
                    $a->vars = [
                        'http'=>'private'
                    ];
                    $a->className = $tag . 'Api';
                    $this->apis[$tag] = $a;                 
                }
            }
        }

        // now iterate through methods and fill those out
        //
        foreach($this->apis as $api) {
            foreach($this->data['paths'][$api->url] as $http_method=>$method_data) {
                $method = new ApiMethod();

                $method->http_method = $http_method;
                $method->name = $http_method . $api->name;
                $method->boilerplate = $method_data['description'] ?? $method_data['summary'] ?? '';

                $vars = ['query'=>[], 'path'=>[], 'body'=>[]];

                if( isset($method_data['requestBody']) ) {
                    $param = new ApiParameter();

                    $ref = $method_data['requestBody']['$ref'];
                    $x = explode('/', $ref);
                    $param->schema = ucfirst($x[count($x)-1]) . 'Model';

                    $param->name = $ref;
                    $param->boilerplate = "Uses class " . $param->schema;
                    $param->required = true;

                    $method->body[] = $param;

                    $line = "use {$this->namespace}\\{$param->schema};";
                    if( !array_search($line, $api->uses) ) {
                        $api->uses[] = $line;
                        $this->external_models[$param->schema] = $ref;     
                    }
                }
                else if( isset($method_data['parameters']) ) {
                    foreach($method_data['parameters'] as $parameter) {
                        // based on "explode" this may be a combo (or) variable
                        // @TODO handle exploded stuff
                        $param_list = [];
                        if( isset($parameter['explode']) && $parameter['explode'] == 1) {
                            $x = explode('/', $parameter['name']);
                            foreach($x as $g) {
                                $f = $parameter;
                                $f['name'] = $g;
                                $param_list[] = $f;
                            }
                        }
                        else {
                            $param_list [] = $parameter;
                        }

                        foreach($param_list as $p1) {
                            $param = new ApiParameter();

                            $p1['name'] = $this->sanitize_name($p1['name']);

                            $param->name = $p1['name'];
                            $param->boilerplate = $p1['description'] ?? $p1['summary'] ?? '';
                            $param->required = $p1['required'] ?? false;

                            if( isset($p1['schema']['$ref']) ) {
                                $x = explode('/', $p1['schema']['$ref']);
                                array_shift($x); // get rid of '#'

                                // find this model
                                $component = $this->recurse_path($this->data, $x);

                                if( isset($component['type']) ) {
                                    switch($component['type']) {
                                        case 'string':
                                            $param->type = 'String';
                                            break;
                                        case 'integer':
                                            $param->type = 'Int';
                                            break;
                                        default:
                                            echo "Got an unexpected type: {$component['type']}\n";
                                    }
                                }
                                else {
                                    echo "Got unexpected component: " . print_r($component, true) . "\n";
                                }
                            }

                           // if( isset($p1['schema']['$ref']) ) {
                            //    $x = explode('/', $p1['schema']['$ref']);
                            //    $ref = $x[count($x)-1] . 'Model';
/* // removed this for now.  some models are just strings or ints, not objects and it was causing more problems than solving
// eventually need to check each one before adding to function call parameters
                            $line = "use {$this->namespace}\\{$ref};";
                            if( !array_search($line, $api->uses) ) {
                                $api->uses[] = $line;
                                $this->external_models[$ref] = $parameter['schema']['$ref'];          
                            }
*/
                           // }

                            $param->schema = $p1['schema'];

                            $method->{$p1['in']}[] = $param;
                        }
                    }
                }
                else {
                    echo "Warning: method did not contain parameters or requestBody\n";
                }

                $api->methods[] = $method;

            // foreach($call_meta->responses as $responses) {
                    // @TODO handle response logic so the interface returns very nice responses
            // }
        

            }
        }

        return null;
    }

    public function save($path) {
        foreach($this->apis as $api) {
            $code = $this->generate_code($api);

            // get last ././...../.././.
            $filespace_parts = explode('\\', $this->namespace);
            $filespace = $filespace_parts[count($filespace_parts)-1];

            $outputPath = $path . $filespace;

            @mkdir($outputPath . '/Api', 0777, true); // if it exists, don't bother with telling us
            
            file_put_contents("$outputPath/Api/{$api->className}.php", $code);
        }
    }

    private function generate_code($api) {
        $output = "<?php\n\nnamespace {$this->namespace}\\Api;\n\n";
        foreach($api->uses as $use) {
            $output .= $use . "\n";
        }
        $output .= "\n";

        $output .= "class {$api->className} implements ClientInterface {\n";
        foreach($api->vars as $name=>$scope) {
            $output .= "\t$scope \${$name};\n";
        }
        $output .= "\tprivate \$url = '{$api->url}';\n";
        $output .= "\n";

        $output .= "\tpublic function __construct(\$endpoint, \$username, \$password) {\n";
        $output .= "\t\t\$this->http = new Client([\n\t\t\t'base_uri'=>\$endpoint,\n\t\t\t'auth'=>[\$username, \$password]\n\t\t]);\n";
        $output .= "\t}\n\n";

        foreach($api->methods as $method) {
            $output .= "\t/**\n\t * {$method->name}\n\t * \n\t * {$method->boilerplate}\n\t *\n\t */\n";
            $output .= "\tpublic function {$method->name}(";

            if( count($method->query) > 0 ) {
                $query_params = [];
                foreach($method->query as $query_param) {
                    $query_params[] = "\${$query_param->name}";
                }

                $output .= implode(", ", $query_params);
            }
            else if( count($method->body) > 0 ) {
                $param_list = [];
                foreach($method->body as $param) {
                    $new_param_name = lcfirst(substr($param->schema, 0, strpos($param->schema, 'Model')));
                    $param_list[] = "{$param->schema} \$$new_param_name";
                }

                $output .= implode(', ', $param_list);
            }
                
            $output .= ") {\n";

            // build the full query list
            $query_list = [];
            foreach($method->query as $q) {
                $query_list[] = $q->name;
            }

            // filter out any null values
            $query_list = array_filter($query_list);

            if( count($query_list) > 0 ) {
                $output .= "\t\t\$query = [\n";
                $output .= "\t\t\t\$" . implode(",\n\t\t\t\$", $query_list);
                $output .= "\n\t\t];\n";
                $output .= "\t\t\$query = array_filter(\$query);\n\n";
                $output .= "\t\t\$url = \$this->url . '?' . http_build_query(\$query);\n\n";
            }

            $body = '';
            if( !empty($method->body) ) {
                $body = ', [], ';
                $param_list = [];
                foreach($method->body as $param) {
                    $new_param_name = lcfirst(substr($param->schema, 0, strpos($param->schema, 'Model')));
                    $param_list[] = "json_encode(\${$new_param_name}->obj->__serialize())";
                }
                $body .= implode(' . ', $param_list);
            }
    
            $output .= "\t\t\$request = new Request('{$method->http_method}', \"{\$url}\"{$body});\n\n";
            $output .= "\t\treturn \$this->sendRequest(\$request);\n";
            $output .= "\t}\n\n";
        }
                    
        $output .= "\tprotected function sendRequest(RequestInterface \$request): ResponseInterface {\n";
        $output .= "\t\treturn \$this->http->send(\$request);\n";
        $output .= "\t}\n\n";
        $output .= "}\n\n";

        return $output;
    }

    public function getExternalModels() {
        return $this->external_models;
    }

    public function getApis() {
        return $this->apis;
    }

    public function getApi($name) {
        return $this->apis[$name] ?? null;
    }

    public function getUrl() {
        return $this->url;
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

    private function sanitize_name($name) {
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

        return $name;
    }
}

class Api {
    public $boilerplate;
    public $name;
    public $url;
    public $namespace;
    public $methods = [];

    public function __construct($name = '') {
        $this->name = $name;
    }
}

class ApiMethod {
    public $boilerplate;
    public $name;
    public $query = [];
    public $path = [];
    public $body = [];
}

class ApiParameter {
    public $name;
    public $boilerplate;
    public $schema;
}