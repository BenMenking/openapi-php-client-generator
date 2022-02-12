# OpenApi PHP Client Generator

pcgen is a script that generates Api and Model classes for PHP based on a supplied OpenApi file. 
It also modified the local composer.json, adding autoload PSR-4 information for a newly generated library. 

## Installation

```
composer require --dev bmenking/openapi-php-client-generator
```

## Usage

```
vendor/bin/pcgen.php --file <file> [-n <namespace>]
```


