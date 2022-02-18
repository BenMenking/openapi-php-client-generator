<?php

class OpenApiParser {
    private $data;
    private $namespace;

    public function __construct($filename, $namespace = "Vendor\\Client") {
        $this->data = json_decode(file_get_contents($filename), true);
        $this->namespace = $namespace;
    }

    public function parse() {
        $k = new ApiHandler($this->data, $this->namespace);

        $models = ['#/components/requestBodies/Bar'];

            $k->parse();
            $k->save(getcwd() . '/src/');
            $models = $k->getExternalModels();

        // handle models
        $model_paths = [];

        foreach($models as $model) {
            $x = explode('/', $model);
            if( !array_search($x[1], $model_paths) ) {
                $model_paths[] = $x[1];
            }
        }

        $j = new ModelHandler($this->data, $this->namespace, $model_paths);

        $j->parse();
        $j->save(getcwd() . '/src/');

        echo "Complete\n";
    }
}
