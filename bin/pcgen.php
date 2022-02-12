#!/usr/bin/env php
<?php

require('vendor/autoload.php');

$cli = new Garden\Cli\Cli();

$cli->description('Convert OpenApi file into PHP apis and models')
    ->opt('file:f', 'OpenApi file', true)
    ->opt('namespace:n', 'Namespace to use, defaults to Client\\Library', false);

$args = $cli->parse($argv, true);

$namespace = $args->getOpt('namespace') ?? "Client\\Library\\";
$filespace = explode('\\', $namespace)[1];

$namespace_parts = explode('\\', $namespace);

if( count($namespace_parts) < 2 || count($namespace_parts) > 2 ) die("Namespace must consist of only two levels\n");

$outputPath = "src/" . $filespace;

@mkdir(getcwd() . '/' . $outputPath . '/Api', 0777, true); // if it exists, don't bother with telling us
@mkdir(getcwd() . '/' . $outputPath . '/Model', 0777, true); // if it exists, don't bother with telling us


$api = json_decode(file_get_contents($args->getOpt('file')), null, 1024, JSON_INVALID_UTF8_IGNORE);

echo "Creating schemas...\n";

foreach($api->components->schemas as $name=>$component) {
    if( isset($component->type) && $component->type !== 'object' ) continue;

    $document = "<?php \n\n";
    $document .= "namespace $namespace\\Model;\n\n";
    $document .= "class {$name}Model {\n";

    if( isset($component->properties) ) {
        if( !isset($component->properties) ) {
            $component->properties = [];
        }

        foreach($component->properties as $component_name=>$prop) {
            if( isset($prop->description) ) {
                $document .= "\t/* {$prop->description} */\n";
            }

            if( !isset($prop->type) ) {
                $prop->type = "foobar";
            }

            switch($prop->type) {
                case "string":
                    if( isset($prop->enum) && count($prop->enum) == 1 ) {
                        $document .= "\tpublic \$$component_name = '{$prop->enum[0]}';\n\n";                        
                    }
                    else {
                        $document .= "\tpublic \$$component_name = null;\n\n";
                    }
                    break;
                case "integer":
                    $document .= "\tpublic \$$component_name = null;\n\n";
                    break;
                case "boolean":
                    $document .= "\tpublic \$$component_name = null;\n\n";                    
                    break;
                case "array":
                    $document .= "\tpublic \$$component_name = null;\n\n";                    
                    break;
                case "number":
                    $document .= "\tpublic \$$component_name = null;\n\n";                                        
                    break;
                case "object":
                    $document .= "\tpublic \$$component_name = null;\n\n";                                        
                    break;
                default:
                    $document .= "\tpublic \$$component_name = null;\n\n";                                        
            }

        }
    }
    else if( isset($component->type) ) {
        echo "Creating no-property class $name\n";

    }
    else {
        echo "Name: $name\n";
        print_r($component);
        die("default failed\n");
    }

    if( isset($component->required) && $component->required ) {
        $document .= "\tprivate \$required = ['" . implode("', '", $component->required) . "'];\n\n";
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

    file_put_contents(getcwd() . "/$outputPath/Model/{$name}Model.php", $document);
}

echo "Creating 'requestBodies'...\n";

foreach($api->components->requestBodies as $name=>$component) {
    $name = ucfirst($name);

    $contentType = array_keys((array)$component->content)[0];
    $ref = $component->content->{$contentType}->schema->{'$ref'};
    $xf = explode('/', $ref);
    $className = ucfirst($xf[count($xf)-1]);

    $document = "<?php \n\n";
    $document .= "namespace $namespace\\Model;\n\n";
    $document .= "use $namespace\\Model\\{$className}Model;\n\n";

    $document .= "class {$name}Model {\n";
    $document .= "\tpublic String \$contentType = '$contentType';\n";
    $document .= "\tpublic \$obj = null;\n\n";

    $document .= "\tpublic function __construct({$className}Model \$obj) {\n";
    $document .= "\t\t\$this->obj = \$obj;\n";
    $document .= "\t}\n\n";


    $document .= "}\n\n";

    file_put_contents(getcwd() . "/$outputPath/Model/{$name}Model.php", $document);
}

$models = [];

// preprocess all Model components so we know what they are
foreach($api->components->schemas as $name=>$component) {
    $models[$name] = $component;
    
}

$paths = [];
$first_tag = null;

foreach($api->paths as $url=>$path) {
    $tag = null;

    // generate name
    foreach($path as $http_method=>$operations) {
        if( !isset($operations->operationId) ) {
            continue;
        }

        $op = [
            'operation'=>$operations->operationId,
            'method'=>strtolower($http_method) . ucfirst($operations->operationId),
            'summary'=>$operations->summary ?? ''
        ];

        if( $tag === null ) {
            $tag = $operations->tags[0];
        }

        $parameters = [];

        if( isset($operations->parameters) ) {
            foreach($operations->parameters as $par) {
                if( !isset($par->schema->type) ) $par->schema->type = '';

                $params = [];

                if( isset($par->explode) && $par->explode ) {
                    $p2 = explode('/', $par->name);
                    foreach($p2 as $qt) {
                        $px = clone $par;
                        $px->name = $qt;
                        $params[] = $px;
                    }
                }
                else {
                    $params[] = $par;
                }

                //echo "Got " . count($params) . " params to work with\n";

                foreach($params as $p3) {
                    switch($p3->schema->type) {
                        case 'integer':
                            $p3->type = 'Int';
                            break;
                        case 'string':
                            $p3->type = 'String';
                            break;
                        case 'boolean':
                            $p3->type = 'Bool';
                            break;
                        default:
                            if( isset($p3->schema->{'$ref'}) ) {
                                $f = explode('/', $p3->schema->{'$ref'});
                                $obj = ucfirst($f[count($f)-1]);

                                // find out what type the reference really is (some are objects, some strings)
                                if( $models[$obj] === 'object') {
                                    $p3->type = $obj . 'Model';
                                }
                                else { // ass-u-me string
                                    $p3->type = ucfirst($models[$obj]->type) . 'Model';
                                }
                            }
                            else {
                                echo "!!Got default type: {$p3->schema->type}\n";
                                $p3->type = 'object';
                            }
                            break;
                    }
                    $parameters[] = $p3;
                }
            }
        }

        if( isset($operations->requestBody) ) {
            $ref = explode('/', $operations->requestBody->{'$ref'});

            $x = new stdClass();
            $x->in = 'body';
            $x->required = true;
            $x->type = 'object';
            $x->name = ucfirst($ref[count($ref)-1]) . 'Model';
            $parameters[] = $x;
        }

        $op['parameters'] = $parameters;
        $op['http_method'] = strtoupper($http_method);
        $op['url'] = $url;
        $paths[$tag][] = $op;
    }

    $paths[$tag]['url'] = $url;
}
 
foreach($paths as $className=>$path) {
    $className = strtr($className, [' '=>'']);

    if( empty($className) ) continue;
    
    $output = "<?php\n\nnamespace $namespace\\Api;\n\n";
    $use_output = [
        "use Psr\\Http\\Client\\ClientInterface;",
        "use Psr\\Http\\Message\\RequestInterface;",
        "use Psr\\Http\\Message\\ResponseInterface;",
        "use GuzzleHttp\\Client;",
        "use GuzzleHttp\\Psr7\\Request;"
    ];

    echo "Creating Api $className\n";

    $class_output = "class {$className}Api implements ClientInterface {\n";
    $class_output .= "\tprivate \$http;\n\n";
    $class_output .= "\tpublic function __construct(\$endpoint, \$username, \$password) {\n";
    $class_output .= "\t\t\$this->http = new Client([\n\t\t\t'base_uri'=>\$endpoint,\n\t\t\t'auth'=>[\$username, \$password]\n\t\t]);\n";
    $class_output .= "\t}\n\n";

    $query_vars = [];
    $path_vars = [];
    $body_vars = [];

    foreach($path as $operation) {
        if( !isset($operation['method']) ) continue;

        // be sure to remove spaces from method... why?! and pretty it up too
        if( strpos($operation['method'], ' ') !== false ) {
            $ft = explode(' ', $operation['method']);
            for($a = 1; $a < count($ft); $a++) {
                $ft[$a] = ucfirst($ft[$a]);
            }
            $operation['method'] = implode('', $ft);
        }

        $class_output .= "\t/**\n\t * {$operation['method']}\n\t * \n\t * {$operation['summary']}\n\t *\n\t */\n";
        $class_output .= "\tpublic function {$operation['method']}(";

        /* add the function parameters */
        $parameters = [];

        foreach($operation['parameters'] as $param) {
            if( isset($param->in)  ) {    
            
                if( $param->{'type'} === 'object' ) {
                    $parameters[] = $param->name . ' $' . lcfirst($param->name) . ((isset($param->required) && $param->required)?'':' = null');
                    $use_text = "use $namespace\\Model\\" . $param->name . ";";
                    if( array_search($use_text, $use_output) === false) {
                        $use_output[] = $use_text;
                    }
                }
                else {
                    $name = strtr($param->name, ['.'=>'_']);
                    $parameters[] = $param->{'type'} . ' $' . strtolower($name)  . ((isset($param->required) && $param->required)?'':' = null');
                }

                switch($param->in) {
                    case 'body':
                        $body_vars[] = [$param->name=>'$' . $param->name];
                        break;
                    case 'query':
                        $query_vars[] = [$param->name=>'$' . $param->name];
                        break;
                    case 'path':
                        $operation['url'] = str_replace('{' . $param->name . '}', '$' . strtolower($param->name), $operation['url']);
                        break;
                    default:
                        echo "Got unexpected 'in' case: {$param->in}\n";
                }
            }
        }

        $class_output .= implode(', ', $parameters) . ") {\n";

        // figure out anything that would be in the query line @TODO handle all this above!
        $query_list = [];
        $body_payload = false;
        $path_list = [];

        foreach($operation['parameters'] as $p) {
            $name = strtr($p->name, ['.'=>'_']);

            if( $p->in === 'query' ) {
                $query_list[] = "\t\t\t'{$name}'=>\${$name}";
            }
            else if( $p->in === 'body' ) {
                $body_payload = lcfirst($p->name);
            }
            else if( $p->in === 'path' ) {
                $path_list[] = $name;
            }
        }
        
        $query_list = array_filter($query_list);
        if( count($query_list) > 0 ) {
            $class_output .= "\t\t\$query = [\n";
            $class_output .= implode(",\n", $query_list);
            $class_output .= "\n\t\t];\n";
            $class_output .= "\t\t\$query = array_filter(\$query);\n\n";
        }

        $class_output .= "\t\t\$options = [];\n";
        if( count($query_list) > 0 ) {
            $class_output .= "\t\tif( count(\$query) > 0 ) {\n";
            $class_output .= "\t\t\t\$options['query'] = \$query;\n";
            $class_output .= "\t\t}\n\n";            
        }

        $body = '';
        if( $body_payload ) {
            //$class_output .= "\t\t\$options['body'] = json_encode(\${$body_payload}->obj->__serialize());\n";
            $body = ", [], json_encode(\${$body_payload}->obj->__serialize())";
        }

        $class_output .= "\t\t\$request = new Request('{$operation['http_method']}', \"{$operation['url']}\"{$body});\n\n";
        $class_output .= "\t\treturn \$this->sendRequest(\$request);\n";
        $class_output .= "\t}\n\n";
    }

    $output .= implode("\n", $use_output) . "\n\n";
    $output .= $class_output;
    $output .= "\tpublic function sendRequest(RequestInterface \$request): ResponseInterface {\n";
    $output .= "\t\treturn \$this->http->send(\$request);\n";
    $output .= "\t}\n\n";
    $output .=  "}\n\n";

    file_put_contents(getcwd() . "/$outputPath/Api/{$className}Api.php", $output);
}

echo "Updating composer.json\n";

if( !file_exists('composer.json') ) die("Could not find composer.json.  Are you in the correct directory?\n");

$composer = json_decode(file_get_contents('composer.json'), true);

// need to add autoload/psr-4
if( isset($composer['autoload']) ) unset($composer['autoload']);

$composer['autoload'] = [
    'psr-4'=>[
        $namespace . '\\'=>$outputPath
    ]
];

file_put_contents('composer.json', json_encode($composer, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

echo "Completed\n";

