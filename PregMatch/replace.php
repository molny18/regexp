<?php

declare(strict_types=1);

$userInputs = [
    '12345678-1-22',
    '12344678122',
    '12222222-122',
];

$expectedTaxNumbers = [
    '12345678-1-22',
    '12344678-1-22',
    '12222222-1-22' ,
];
$created=[];
$pattern = '/^(\d{8})([-]?)(\d{1})([-]?)(\d{2})$/';
$replacement = '$1-$3-$5';
foreach ($userInputs as $input){
    preg_match($pattern,$input,$match);
    $created[]= preg_replace($pattern,$replacement,$input);
}

if($created === $expectedTaxNumbers){
    echo 'PASSED'.PHP_EOL;
}else{
    echo 'FAILED'.PHP_EOL;
}