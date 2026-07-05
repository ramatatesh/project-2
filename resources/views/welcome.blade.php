
<html>

<?php
$a=array(1,5,6,2);
$max=$a[0];
for($i=0;$i<3 ; $i++){
    if($a[$i]<$a[$i+1]){
$max=$a[$i+1];
$tem=$a[$i];
$a[$i+1]=$tem;
$a[$i]=$max;

}
}

print_r($a);

?>
</html>