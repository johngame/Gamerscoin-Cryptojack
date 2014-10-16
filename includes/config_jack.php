<?php
//debug mode - set to true for debug output
$debug = false;
//set to true to redirect users to maintenance.php
$maintenance = true;
/* Configuration variables for the JSON-RPC server */
$rpc_noob = 'HOST';
$rpc_noob = 'PORT';
$rpc_noob = 'USER';
$rpc_noob = 'PASS';
/* Include the JSON-RPC library, and connect to the server */
require_once('jsonRPCClient.php');
$bc = new jsonRPCClient('http://' . $rpc_user . ':' . $rpc_pass . '@' . $rpc_host . ':' . $rpc_port);
//Change to MIN/MAX Bet amount allowed for the supported currency.
$min = 1;
$max = 100;
// Increments for bets placed
$step = 1;
//Change to the name of coin the game is supporting eg Bitcoin
$coin = "Gamerscoin";
//Change to the ticker symbol of currency you are supporting eg: Bitcoin = BTC
$currency = "GMC";
//Name of account used inside wallet for payouts
$bank = "CryptoJack";
//Set minimum amount that bank should have to allow game to start
$minbank=1000;
