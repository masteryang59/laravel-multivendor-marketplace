<?php

namespace App\Http\Controllers;

abstract class Controller
{
    //
}

function throttleRequests($data = null) {
    if ($data === null) return null;
    return $data;
}