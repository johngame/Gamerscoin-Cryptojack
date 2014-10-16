<?php
include("./includes/config_jack.php");
@session_start();
if (isset($_SESSION['acckey']))
{
$key = $_SESSION['acckey'];
if (!isset($_SESSION['refresh']) && (isset($_SESSION['txid'])))
{
$_SESSION['refresh']= 'once';
$min = 0;
}
else
{
$min = $min;
}
if (ctype_alnum($key))
{
$balance = $bc->getbalance($key,0);
if ($balance >= $min)
{
echo "1";
}
else
{
echo "0";
}
}
}
else
{
header('location: ./index.php');
}
