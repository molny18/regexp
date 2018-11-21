<?php

declare(strict_types=1);

$text = 'Lorem ipsum dolor sit amet, consectetur adipiscing elit. Vivamus consequat, enim ac venenatis facilisis, risus justo rhoncus nibh, eget semper felis ante eu ante. In hac habitasse platea dictumst. Nulla facilisi. Maecenas justo nulla, blandit sit amet justo et, facilisis tristique turpis. Sed bibendum sem tortor, in vulputate massa pellentesque at. Pellentesque sem ante, pretium ut mi at, rutrum egestas ante. Quisque lorem nisl, sodales id mauris id, vehicula tempus est. Duis eu aliquam arcu. Vivamus id urna augue. Mauris leo nunc, dictum vel ipsum et, sagittis porttitor libero. Suspendisse aliquam felis ac velit tempus, nec sollicitudin mi congue. Integer sagittis mauris sit amet iaculis dapibus. Nulla nec nibh eget ipsum aliquam tristique non eget sapien. Pellentesque metus turpis, efficitur ac sem et, rutrum aliquet nunc. Maecenas at nisl elementum, efficitur odio ac, consequat quam. Nunc dictum porttitor luctus.

Integer eleifend scelerisque elementum. Ut sit amet orci augue. Morbi vitae dolor vestibulum, finibus diam et, elementum justo. Donec varius maximus vulputate. Nullam ac placerat dui. Cras in lorem ipsum. Quisque mollis ante ut ante volutpat scelerisque.

Pellentesque pharetra eros vel porta fermentum. Duis erat erat, viverra vitae volutpat in, facilisis at nulla. Mauris imperdiet aliquam ex sit amet fringilla. Integer varius placerat dui, in scelerisque lorem blandit sed. Nulla lacinia scelerisque tellus, ut sagittis velit tempor id. Lorem ipsum dolor sit amet, consectetur adipiscing elit. Nulla vulputate condimentum volutpat. Nullam in tellus dui. Praesent tincidunt pulvinar leo a blandit. Nulla facilisi. Ut sed est vestibulum purus lacinia pellentesque vel ut ligula.

Nam ipsum enim, pulvinar sit amet dapibus eget, dignissim vitae diam. Nunc ac lacinia dolor, et hendrerit lorem. Quisque euismod at dolor id mattis. Vestibulum ante ipsum primis in faucibus orci luctus et ultrices posuere cubilia Curae; Vestibulum egestas molestie diam, sed sodales ex rhoncus aliquet. Quisque non accumsan magna, varius tincidunt est. Aliquam ultricies urna a justo eleifend pharetra.

Sed egestas mauris at fringilla sodales. Morbi aliquet quam in justo porttitor imperdiet. Fusce quis arcu mi. Aenean a consequat ante. Nullam metus justo, rutrum quis turpis a, feugiat efficitur ligula. Pellentesque ac urna non mauris pulvinar porttitor eu sed ante. Phasellus convallis interdum libero, rutrum tempor erat pretium vitae. Suspendisse potenti. Cras viverra sagittis eleifend.';

$array = [
    'AAAAbbbCCC',
    'BBBccc',
    'KKllKK',
    'TnnnBBBB'
];


/*Az összes Mondatkezdő szót keresd meg a $ŧext változóban*/
$pattern = '/[A-Z][a-z]*/m';
preg_match_all($pattern, $text, $m);

var_dump($m);

/*Az összes egymást követő 2 vagy több egymást követő nagybetűt keresd meg az $array változóban ,
 ha az egymást követő nagybetűk száma a 2 töbszöröse akkor az több találtnak számítson pl : AAA - egy találat AAAA-két találat*/
$pattern = '/([A-Z]){2,}/U';
$result = [];
$expectedCount = 8;
$count = 0;
foreach ($array as $item){
    $c = preg_match_all($pattern,$item,$matches);
    $count = $count+$c;
}
if($expectedCount === $count){
    echo 'PASSED';
}else{
    echo 'FAILED';
}