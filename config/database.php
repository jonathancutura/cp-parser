<?php

return array(
	'log'=>true,

	/*
	|--------------------------------------------------------------------------
	| Database Connections
	|--------------------------------------------------------------------------
	|
	| Here are each of the database connections setup for your application.
	| Of course, examples of configuring each database platform that is
	| supported by Laravel is shown below to make development simple.
	|
	|
	| All database work in Laravel is done through the PHP PDO facilities
	| so make sure you have the driver for your particular database of
	| choice installed on your machine before you begin development.
	|
	*/
	'default' => 'mysql',

	'connections' => array(
	    'mysql' => array(
	    	'read' => [
		        'host' => env('DB_HOST_READ','localhost'),
		    ],
		    'write' => [
		        'host' => env('DB_HOST_WRITE','localhost')
		    ],
	        'driver'    => 'mysql',
	        'database'  => env('DB_DATABASE','carepredict_forge'),
	        'username'  => env('DB_USERNAME','root'),
	        'password'  => env('DB_PASSWORD',''),
	        'charset'   => 'utf8',
	        'collation' => 'utf8_unicode_ci',
	        'prefix'    => ''
    	),

    	'dumpdBCon' => array(
		    'host' 		=> env('DB_HOST','localhost'),
	        'driver'    => 'mysql',
	        'database'  => env('DB_DATABASE1','carepredict_dump'),
	        'username'  => env('DB_USERNAME','root'),
	        'password'  => env('DB_PASSWORD',''),
	        'charset'   => 'utf8',
	        'collation' => 'utf8_unicode_ci',
	        'prefix'    => ''
    	)

    )

);
