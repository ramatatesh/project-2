<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;

class FirstController extends Controller
{
    public function getUsers()
{
$fileContent= file_get_contents('http://localhost:80/users.json');
$jsonContent= json_decode($fileContent,true);

foreach($jsonContent as $name){
    $name = strtoupper($name);
    echo "$name <br>";
}
}
}
