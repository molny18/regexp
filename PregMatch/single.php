<?php

declare(strict_types=1);

$expectedMatches = 3;
$yourMatchCount = 0;
$userInputs = [
    'xxxxxxxx-y-zz',
    '12345678-1-22',
    '12345678122',
    '123csdf1-122',
    '12345678-122',
    '123456781-122',
    '12345678--1-22',
    '12345678-1-2'
];
/*
 *  Töltsd ki a patternt!
 * */
$pattern = '/^\d{8}[-]?\d{1}[-]?\d{2}$/';

foreach ($userInputs as $input){

    if(preg_match($pattern,$input)){
        $yourMatchCount++;
    }
}

if($yourMatchCount === $expectedMatches){
    echo 'PASSED'.PHP_EOL;
}else{
    echo 'FAILED'.PHP_EOL;
}
