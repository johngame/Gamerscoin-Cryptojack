<?php 
include './includes/config_jack.php'; 
if ($maintenance === true)
{
header('location: ../maintenance.php');
exit;
}
function debug($something) {
  #echo $something."<br>\n";
}

class State {
  // invalid states
  const INVALID = 0;

  // playing states
  //const START = 1;
  //const OFFER_INSURANCE = 2;
  //const PLAYING = 3; // user decision - hit, stand, double, split not supported
  //const PLAYING_ACTIVE = 4; // user has already hit (useful for disabling double down)
  //const DEALER = 5; // user stands, dealer must decide next action

  // end game states
  //const END_GAME_STATES_START = 101;
  //const USER_BUST = 101; // user busted
  //const DEALER_BUST = 102; // dealer busted
  //const PUSH = 103; // tie
  //const USER_WIN = 104; // no busting, user score greater than dealer score
  //const DEALER_WIN = 105; // no busting, dealer score greater than user score
  //const USER_BLACKJACK = 106; // user blackjack
  //const DEALER_BLACKJACK = 107; // dealer blackjack
  //const RECONCILED = 108;
  //const END_GAME_STATES_END = State::RECONCILED;

  public static function isActive($state) {
    return $state > State::INVALID && $state < State::END_GAME_STATES_START;
  }

  public static function isEndGame($state) {
    return $state >= State::END_GAME_STATES_START && $state <= State::END_GAME_STATES_END;
  }

  public static function isUserWin($state) {
    return $state == State::USER_WIN || $state == State::DEALER_BUST || $state == State::USER_BLACKJACK;
  }

  public static function isDealerWin($state) {
    return $state == State::DEALER_WIN || $state == State::USER_BUST || $state == State::DEALER_BLACKJACK;
  }
}

class User {
  private $name;
  private $money;
}

class Hand {
  const WIN = 1;
  const PUSH = 0;
  const LOSE = -1;

  private $cards;
  public function __construct() {
    $this->cards = array();
  }

  public function __toString() {
    $string = "";
    $size = sizeof($this->cards);
    if ($size > 0) {
      $string .= $this->cards[0];
    }
    for ($i = 1; $i < $size; $i++) {
      $string .= ', '.$this->cards[$i];
    }
    return $string;
  }

  public function getScore() {
    $totals = $this->getTotals();
    $score = $totals[0];
    if ($totals[0] != $totals[1]) {
      $score = max($totals);
      if ($score > 21) {
        $score = min($totals);
      }
    }
    return $score;
  }

  public function compareTo($hand) {
    $myscore = $this->getScore();
    $yourscore = $hand->getScore();
    $mybust = $this->isBust();
    $yourbust = $hand->isBust();

    $compareTo = Hand::PUSH;
    if ($mybust && $yourbust) {
      $compareTo = Hand::PUSH;
    }
    elseif ($mybust && !$yourbust) {
      $compareTo = Hand::LOSE;
    }
    elseif (!$mybust && $yourbust) {
      $compareTo = Hand::WIN;
    }
    else {
      if ($myscore > $yourscore) {
        $compareTo = Hand::WIN;
      } elseif ($myscore < $yourscore) {
        $compareTo = Hand::LOSE;
      } else {
        $compareTo = Hand::PUSH;
      }
    }
    return $compareTo;
  }

  public function isBust() {
    $bust = true;
    $totals = $this->getTotals();
    foreach ($totals as $t) {
      if ($t <= 21) {
        $bust = false;
        break;
      }
    }
    return $bust;
  }

  public function isBlackjack() {
    $blackjack = false;
    $totals = $this->getTotals();
    $cardssize = sizeof($this->cards);
    if ($cardssize == 2) {
      foreach ($totals as $t) {
        if ($t == 21) {
          $blackjack = true;
        }
      }
    }
    return $blackjack;
  }

  public function add($card) {
    debug("Hand::add($card) for hand - $this");
    array_push($this->cards, $card);
  }

  public function getCards() {
    return $this->cards;
  }

  /**
  * Returns array of totals (array since hands w/ aces yield two scores)
  */
  public function getTotals() {
    $totals = array(0, 0);
    $cardcount = sizeof($this->cards);
    for ($i = 0; $i < $cardcount; $i++) {
      $card = $this->cards[$i];
      $rank = $card->getRank()->getRank();
      switch ($rank) {
        case Rank::ACE:
        $accountedForAce = false;
        for ($j = 0; $j < $i; $j++) {
          if ($this->cards[$j]->getRank()->getRank() == Rank::ACE) {
            $accountedForAce = true;
            break;
          }
        }
        if ($accountedForAce) {
          // already accounted for soft ace, just add one
          $totals[0] += 1;
          $totals[1] += 1;
        } else {
          // account for soft ace - need to add 1 and 11 to totals
          $totals[0] += 1;
          $totals[1] += 11;
        }
        break;
        case Rank::KING:
        case Rank::QUEEN:
        case Rank::JACK:
        $totals[0] += 10;
        $totals[1] += 10;
        break;
        default:
        $totals[0] += $rank;
        $totals[1] += $rank;
        break;
      }
    }
    return $totals;
  }
}

class DealerHand extends Hand {
  public function getUpCard() {
    $cards = $this->getCards();
    return $cards[1];
  }

  public function getDownCard() {
    $cards = $this->getCards();
    return $cards[0];
  }
}

/**
* User's cash
*/
class Wallet {

  private $money;
  private $wager;

  public function __construct($money, $wager) {
    $this->money = $money;
    $this->wager = $wager;
  }

  public function setWager($wager) {
    $this->wager = $wager;
  }

  public function getWager() {
    return $this->wager;
  }

  public function addMoney($money) {
    $this->money += $money;
  }

  public function getMoney() {
    return $this->money;
  }

  public function reconcile($game) {
    $state = $game->getState();
    debug("Wallet::reconcile state [$state] money [".$this->money."]");
    $prize = 0;
    $wager = $this->wager;
    if ($state != State::RECONCILED && State::isEndGame($state)) {
      if ($game->isDoubleDown()) {
        $wager *= 2;
      }

      switch ($state) {
        case State::USER_BLACKJACK:
          $prize = $wager * 1.5;
		include './includes/config_jack.php';
		  $bc->move($bank,$_SESSION['acckey'],(float)$prize);
		  if (isset($_SESSION['wait']))
		  {
		  unset($_SESSION['wait']);
		  }
        break;
        case State::DEALER_BUST:
        case State::USER_WIN:
          $prize = $wager;
		include './includes/config_jack.php';
		  $bc->move($bank,$_SESSION['acckey'],(float)$prize);
		  if (isset($_SESSION['wait']))
		  {
		  unset($_SESSION['wait']);
		  }  
        break;
        case State::DEALER_BLACKJACK:
        case State::USER_BUST:
        case State::DEALER_WIN:
        $prize += -$wager;
		include './includes/config_jack.php';
		$bc->move($_SESSION['acckey'],$bank,(float)$wager);
		  if (isset($_SESSION['wait']))
		  {
		  unset($_SESSION['wait']);
		  }
        break;
      }
      $this->money += $prize;
      debug("Wallet::reconcile final prize [$prize] money [".$this->money."]");
    }
    $game->reconcile();
  }
}

class BlackjackGame {
  private $dealer;
  private $user; // could make this an array eventually for multi-player games
  private $state = State::INVALID;
  private $boughtInsurance = false;
  private $doubleDown = false;

  public function __construct($deck = null) {
    if ($deck == null) {
      $deck = new Standard_4x13_InfiniteDeck();
    }
    $this->deck = $deck;
  }

  public function getState() {
    return $this->state;
  }

  private function setState($state) {
    debug("BlackjackGame::setState [$state]");
    $this->state = $state;
  }

  public function start() {
    debug("BlackjackGame start()");
    $this->dealer = new DealerHand();
    $this->user = new Hand();
    $this->boughtInsurance = false;

    // dealer gets dealt first card
    $this->dealer->add($this->deck->next());

    // user(s) get dealt cards next
    $this->user->add($this->deck->next());
    $this->user->add($this->deck->next());

    // dealer gets dealt last card
    $this->dealer->add($this->deck->next());
    $this->setState(State::START);
    $this->updateState();
  }

  public function reconcile() {
    $this->setState(State::RECONCILED);
  }

  public function getUserHand() {
    return $this->user;
  }

  public function getDealerHand() {
    return $this->dealer;
  }

  public function hitUser() {
    $state = $this->state;
    debug("BlackjackGame::hitUser state [$state]");
    if ($state == State::PLAYING_ACTIVE || $state == State::PLAYING) {
      $this->user->add($this->deck->next());
      if ($this->state == State::PLAYING) {
        $this->setState(State::PLAYING_ACTIVE);
      }
      $this->updateState();
      if ($this->doubleDown) {
        if (State::isActive($this->state)) {
          $this->goDealer();
        }
      }
    }
  }

  public function hitDealer() {
    $card = $this->deck->next();
    debug("BlackjackGame::hitDealer card [$card]");
    $this->dealer->add($card);
  }

  public function goDealer() {
    $this->setState(State::DEALER);
    $totals = $this->dealer->getTotals();
    debug("BlackjackGame::goDealer totals [$totals[0], $totals[1]]");
    // assume dealer can stay on soft 17
    $stay = false;
    if ($totals[0] == $totals[1]) {
      if ($totals[0] >= 17) {
        debug("BlackjackGame::goDealer stay 1");
        $stay = true;
      }
    } else {
      if ($totals[0] >= 17) {
        debug("BlackjackGame::goDealer stay 2");
        $stay = true;
      } else {
        if ($totals[1] >= 17 && $totals[1] <= 21) {
          debug("BlackjackGame::goDealer stay 3");
          $stay = true;
        }
      }
    }
    if ($stay) {
      // done
      $this->updateState();
    } else {
      $this->hitDealer();
      $this->goDealer();
    }

  }

  public function userStands() {
    if (State::isActive($this->state)) {
      $this->goDealer();
    }
  }

  public function userDoubleDown() {
    $state = $this->state;
    debug("BlackjackGame::userDoubleDown state [$state]");
    if ($state == State::PLAYING) {
      $this->doubleDown = true;
      $this->hitUser();
    }
  }

  public function isDoubleDown() {
    return $this->doubleDown;
  }

  public function updateState() {
    debug("updateState state[".$this->state."]");
    if ($this->state == State::START) {
      debug("updateState state START");
      $this->setState(State::PLAYING);
      $this->updateState();
    } else {
      debug("updateState state NOT START");
      if ($this->dealer->isBlackjack()) {
        if ($this->user->isBlackjack()) {
          $this->setState(State::PUSH);
        } else {
          $this->setState(State::DEALER_BLACKJACK);
        }
      } elseif ($this->user->isBlackjack()) {
        $this->setState(State::USER_BLACKJACK);
      } elseif ($this->user->isBust()) {
        $this->setState(State::USER_BUST);
      } elseif ($this->dealer->isBust()) {
        $this->setState(State::DEALER_BUST);
      } elseif ($this->state == State::DEALER) {
        $compare = $this->user->compareTo($this->dealer);
        debug("user hand: ".$this->user.", dealer hand: ".$this->dealer);
        if ($compare == Hand::WIN) {
          $this->setState(State::USER_WIN);
        } elseif ($compare == Hand::LOSE) {
          $this->setState(State::DEALER_WIN);
        } else {
          $this->setState(State::PUSH);
		  
        }
      }
    }
  }
}

class Suit {
  const SPADES = 0;
  const HEARTS = 1;
  const CLUBS = 2;
  const DIAMONDS = 3;

  public static function getRandomSuit() {
    $s = substr(str_shuffle(str_repeat('0123012301230123012301230123012301230123012301230123',5)),0,1);
    return new Suit($s);
  }

  private $suit;

  public function __construct($suit) {
    $this->suit = $suit;
  }

  public function __toString() {
    $string = 'NONE';
    switch ($this->suit) {
      case Suit::SPADES:
      $string = 'Spades';
      break;
      case Suit::HEARTS:
      $string = 'Hearts';
      break;
      case Suit::CLUBS:
      $string = 'Clubs';
      break;
      case Suit::DIAMONDS:
      $string = 'Diamonds';
      break;
      default:
      // error, should never get here
      break;
    }
    return $string;
  }

  public function getSuit() {
    return $this->suit;
  }
}

class Rank {

  const ACE = 1;
  const KING = 13;
  const QUEEN = 12;
  const JACK = 11;

  public static function getRandomRank() {
  $deckarray = $_SESSION['deck'];
  $_SESSION['shex'] = $deckarray[$_SESSION['num']];
  $_SESSION['num'] = ($_SESSION['num'] + 1);
  if ($_SESSION['num'] == 52)
  {
  $_SESSION['shuffled'] = 'newdeck';
  $_SESSION['num'] = 0;
  $_SESSION['gamekey'] = str_shuffle('123456789abcd123456789abcd123456789abcd123456789abcd'); //shuffle 52 card deck in hex using numbers 1 - 13 = Ace > King
  $gamekey = $_SESSION['gamekey'];
  $deck=substr(chunk_split($_SESSION['gamekey'], 1, ','), 0, -1); 
  $salthash = hash('sha256', $deck);
  $_SESSION['lastdeck'] = $_SESSION['currentdeck'];
  unset($_SESSION['currentdeck']);
  $_SESSION['wait'] = 'letswait';
  }
  $sdec=hexdec($_SESSION['shex']);
  return new Rank($sdec);
  }

  private $rank;
  public function __construct($rank) {
    $this->rank = $rank;
  }

  public function __toString() {
    $string = $this->rank.'';
    switch ($this->rank) {
      case Rank::ACE:
        $string = 'Ace';
        break;
      case Rank::KING:
        $string = 'King';
        break;
      case Rank::QUEEN:
        $string = 'Queen';
        break;
      case Rank::JACK:
        $string = 'Jack';
        break;
    }
    return $string;
  }

  public function getRank() {
    return $this->rank;
  }
}

class Card {
  private $suit;
  private $rank;

  public function __construct($rank, $suit = null) {
    if ($suit == null) {
      $suit = Suit::getRandomSuit();
    }
    $this->suit = $suit;
    $this->rank = $rank;
  }

  public function __toString() {
    return $this->rank.' of '.$this->suit;
  }

  public function getSuit() {
    return $this->suit;
  }

  public function getRank() {
    return $this->rank;
  }
}


/**
* Casinos have different blackjack formats - 6 deck 'shoes' are common, so are continuously shuffled 'shoes'
* the interface is useful to abstract away these details from classes that use Decks like BlackjackGame
*/
interface Deck {
  public function next();
}

class Standard_4x13_InfiniteDeck implements Deck {
  private $cards;
  public function next() {
    $rank = Rank::getRandomRank();
    $card = new Card($rank);
    return $card;
  }
}

?>
<!DOCTYPE HTML>

<html>
	<head>
		<title>CryptoJack</title>
		<meta http-equiv="content-type" content="text/html; charset=utf-8" />
		<meta name="description" content="" />
		<meta name="keywords" content="Roulette, Crypto, Frozen, Frozencoin" />
		<script src="//code.jquery.com/jquery-1.9.1.js"></script>
		<script src="//code.jquery.com/ui/1.10.4/jquery-ui.js"></script> 
		<script src="./js/config.js"></script>
		<script src="./js/skel.min.js"></script>
        <script type="text/javascript" src="./js/jquery.maphilight.js"></script>
<script type="text/javascript">
$(function() {	
$( "#Dialog" ).dialog({
autoOpen: false,
show: {
effect: "blind",
duration: 250
},
hide: {
effect: "explode",
duration: 500
}
});});
function newMessage(Message) {
$("#DialogText").html("<strong>" + Message + "</strong>");
$( "#Dialog" ).dialog( "open" );
}; 
</script>	
		<link rel="stylesheet" href="//code.jquery.com/ui/1.10.4/themes/smoothness/jquery-ui.css">
		<link href="http://fonts.googleapis.com/css?family=Open+Sans:300,600,700" rel="stylesheet" />
        <noscript>
			<link rel="stylesheet" href="./css/skel-noscript.css" />
			<link rel="stylesheet" href="./css/style.css" />
			<link rel="stylesheet" href="./css/style-desktop.css" />
		</noscript>
</head>
<body>
<!-- Nav -->
			<nav id="nav">
				<ul class="container">
				    <!--<li><a href="../">Intro</a></li>-->
					<li><a href="#">Cryptojack for <?php echo $coin;?></a></li>
					<li><a href="#howto">How To Play</a></li>
					<li><a href="#odds">Payout Odds</a></li>
					<li><a href="#fair">Fairness</a></li>
					<li><a href="#converter">Play Data Converter</a></li>
				</ul>
			</nav>

		<!-- Home -->
		
			<div class="wrapper wrapper-style2">
			<header>
						<?php @session_start(); if ((!isset($_POST['Deal'])) && (!isset($_SESSION['reset'])) && (!isset($_POST['Hit'])) && (!isset($_POST['Stand'])) && (!isset($_POST['Wager'])) && (!isset($_POST['Double']))){ echo "<h2><img src='../gamer.png' title='Cryptojack for " . $coin . "' width='29.3' height='29.3'>&nbsp;Cryptojack</h2>
						<i>The 100% provably fair Blackjack game for Cryptocurrency!</i>
					</header>";};?>
				<article class="container" id="play">
					
					
<?php
if (!isset($_SESSION['txid']))
{
$checkbank=$bc->getbalance($bank);
if ($checkbank < $minbank)
{
header('location: ../maintenance.php');
exit;
}
}
if (isset($_SESSION['gamekey']))
{
}
else
{
$_SESSION['gamekey'] = str_shuffle('123456789abcd123456789abcd123456789abcd123456789abcd'); //shuffle 52 card deck in hex using numbers 1 - 13 = Ace > King
}
if (isset($_SESSION['acckey']))
{
}
else
{
$acc = substr(str_shuffle(str_repeat('Aa1Bb2Cc3Dd4Ff5Gg6Hh7Ii8Jj9KkLl1Mm2Nn3Pp4Qq5Rr6Ss7Tt8Uu9VvWw1Xx2Zz3',5)),0,15); // Generate account key (15 chars)
$add = $bc->getnewaddress($acc);
$_SESSION['acckey'] = $acc;
$_SESSION['address'] = $add;
}
$balance = $bc->getbalance($_SESSION['acckey'], 0); 
if (($balance > 0) && (!isset($_SESSION['sender'])))
{
 foreach ($bc->listtransactions($_SESSION['acckey'],1) as $transaction) {
 if ($transaction['category'] == 'receive') {
  //$betamount  = $transaction['amount'];
  $_SESSION['txid'] = $transaction['txid'];
  $txdetails = $bc->getrawtransaction($transaction['txid'],1);
  $getvin = $txdetails['vin']['0']['txid'];
  $sender = $txdetails['vin']['0']['vout'];
  $txdetails = $bc->getrawtransaction($getvin,1);
  $_SESSION['sender'] = $txdetails['vout'][$sender]['scriptPubKey']['addresses']['0'];
}
}
}
$gameover = false;
if (!isset($_SESSION['wager']))
{
$_SESSION['wager'] = 1;
}
if (!isset($_SESSION['shuffled']))
{
$_SESSION['shuffled'] = '';
}
$_SESSION['balance'] = $balance;
$gamekey = $_SESSION['gamekey'];
$deck=substr(chunk_split($_SESSION['gamekey'], 1, ','), 0, -1); 
//$algo=; // We use SHA256 Hash Algorithm
if (isset($_SESSION['txid']))
{
$salthash = hash('sha256', $deck . $_SESSION['txid']);
}

if ($debug === true)
{
echo "Deck= " . $deck . "<br>";
$_SESSION['shuffled'] = 'newdeck';
}
$_SESSION['currentdeck'] = $deck;
$_SESSION['deck']=explode(',', $deck);
if (!isset($_SESSION['num']))
{
$_SESSION['num'] = 0;
}
else 
{
$_SESSION['num'] = $_SESSION['num'];
}
$add = $_SESSION['address'];
$acc = $_SESSION['acckey'];
/*if ($balance > $max)
{
define('MAX_WAGER', $max);
}
else
{
define('MAX_WAGER', $balance);
}*/
if ($balance >= $min)
{
define('MIN_WAGER', 1);
}
else
{
define('MIN_WAGER', 0);
}
define('WAGER_STEP', 1);

function getImage($card) {
  if($card) {
    $rank = $card->getRank()->getRank();
    if($rank == Rank::JACK) {
      $rank = 'j';
    } elseif($rank == Rank::QUEEN) {
      $rank = 'q';
    } elseif($rank == Rank::KING) {
      $rank = 'k';
    }
    $suit = strtolower(substr($card->getSuit(), 0, 1));
    $image = 'images/'.$suit.$rank.'.png';
    return "<img src=\"$image\" />";
  }
}
#$deck = new Deck();

$resetgame = false;
$resetwallet = false;
if (isset($_POST['Reset'])) {
  $resetgame = true;
}

if ($resetgame) {
if ($balance > 0) {
 $bc->move($acc,$bank,(float)$balance);
 $send = $bc->sendfrom($bank,$_SESSION['sender'],(float)$balance);
 $sent = "<strong><font color='red' >Payment Sent - TXID: </font></strong>" . $send . "<br>";
 $showdeck = "<strong><font color='red' >Last Used 52 Card Data: </font></strong>" . $deck . "<br>";
 $balance = 0;
 $gameover = true;
 session_unset();
 session_destroy();
 } 
 else
 {
 $showdeck = "<strong><font color='red' >Last Used 52 Card Data: </font></strong>" . $deck . "<br>";
 $balance = 0;
 $gameover = true;
 session_unset();
 session_destroy();
}
}
if (isset($_POST['Deal'])) {
  $deal = true;
}

if (!isset($_SESSION['wallet'])) {
  $resetwallet = true;
}

if ($resetwallet) {
  $wallet = new Wallet($balance, 1);
  $_SESSION['wallet'] = $wallet;
} else {
  $wallet = $_SESSION['wallet'];
}


if (isset($_POST['Wager']) && $_POST['Wager'] <= $balance && $_POST['Wager'] >= MIN_WAGER ) 
{
if ($balance >= $_POST['Wager'])
{ 

  if (!isset($game) || $game == null || !State::isActive($game->getState())) {
    $wallet->setWager($_POST['Wager']); $_SESSION['wager'] = $_POST['Wager'];
  }
}
else
{
header("location: index.php");
}
}

if (@$deal) {
  $game = new BlackjackGame();
  $_SESSION['game'] = $game;
  $game->start();
} else {
  $game = @$_SESSION['game'];
}

$dealEnabled = 1;
$hitEnabled = 0;
$standEnabled = 0;
$doubleEnabled = 0;
$insuranceEnabled = 0;
$wagerEnabled = 1;
$gameOn = false;
if ((isset($game)) && ($balance > 0))
{
if ($game != null) {
  $gameOn = true;
  $wagerEnabled = 1;
  # check for user actions
  if (isset($_POST['Hit'])) {
    $game->hitUser();
  } elseif (isset($_POST['Stand'])) {
    $game->userStands();
  } elseif (isset($_POST['Double'])) {
    $game->userDoubleDown();
  } elseif (isset($_POST['BuyInsurance'])) {
    $game->buyInsurance(true);
  } elseif (isset($_POST['DeclineInsurance'])) {
    $game->buyInsurance(false);
  }

  $upcard = $game->getDealerHand()->getUpCard();
  $dealerScore = $game->getDealerHand()->getScore();
  $dealerHand = $game->getDealerHand();
  $userScore = $game->getUserHand()->getScore();
  $userHand = $game->getUserHand();
  $state = $game->getState();

  if (State::isActive($state)) {
    $dealEnabled = 0;
    $hitEnabled = 1;
    $standEnabled = 1;
    $wagerEnabled = 0;
	$dodouble = ($_SESSION['wager'] * 2);
	if ($balance >= $dodouble)
	{
    if ($state == State::PLAYING) {
      $doubleEnabled = 1;
    }
	}
  } else {
    $wallet->reconcile($game);
    unset($_SESSION['game']);
	$dealEnabled = 1;
	$_SESSION['balance'] = $bc->getbalance($_SESSION['acckey'], 0); 
	$balance = $_SESSION['balance'];
if (($_SESSION['balance'] < $min) || ($_SESSION['balance'] < $_SESSION['wager'])) 
{
if (($_SESSION['balance'] < $_SESSION['wager']) && ($_SESSION['balance'] < $min))
{
 $dealEnabled = 0;
 $_SESSION['reset'] = 'set';
 $wallet->setWager(1);
 unset($_SESSION['wager']);
 }
 else
 {
  $dealEnabled = 1;
 //$_SESSION['reset'] = 'set';
 $wallet->setWager(1);
 unset($_SESSION['wager']);
 }
}
    $hitEnabled = 0;
    $standEnabled = 0;
    $doubleEnabled = 0;
	//$wallet->setWager(1);
    //unset($_SESSION['wager']);
  }

 }
}
$script = ("<script type='text/javascript' >function check(){\$.get('./check.php', function(data) {if (data != 0){window.location=window.location;}});};window.setInterval(check, 10000);</script>");	
if ($balance >= $min)
{
$script='';
}
  function displayDealerHand($state, $upcard, $dealerHand, $dealerScore) {
    $html = '';
    if (State::isActive($state)) {
      $html .= "<img src='./images/b2fv.png'>" . getImage($upcard);
    }
    else {
      $html .= getImage(@$upCard);
      $cards = $dealerHand->getCards();
      foreach($cards as $card) {
        $html .= getImage($card);
      }
      $html .= '<br>';
      $html .= "<font color='white'>The dealer has: ".$dealerScore."</font>";
    }
    return $html;
  }
?>
<html>
  <head>
	<?php echo $script; ?>
    </head>
<body <?php if (isset($_SESSION['shuffled']))
{
if ($_SESSION['shuffled'] == 'newdeck')
{
echo "onload = \"newMessage('Deck Shuffled!')\""; 
unset($_SESSION['shuffled']);
}
}?>>
<center>
  <?php if (($balance < $min) && ($gameover === false) && ($balance == 0) && (!isset($_SESSION['txid'])))
  {
  echo "<strong>To Play - Send Funds to:</strong> <br><input type='text' class='rounded' value='" . $add . "' READONLY>";
  } 
  else if ($balance >= $min)
  {
      echo "<h2>Balance: " . $balance . " " . $currency . "</h2>";
	  }
	  else if  (($gameover === false) && ($balance < $min) && ($balance >= 0) && (isset($_SESSION['txid'])))
	  {
	  echo "<strong>Your balance is below the minimum bet amount of " .$min . " " . $currency . "</strong><br><strong>To continue - Send Funds to:</strong><br><input size='42' type='text' class='rounded' value='" . $add . "' READONLY>";
	   $_SESSION['reset'] = 'set';
      }
	  else
	  {
	  echo "<h2><font color='red' >Game Resetting, Please Wait....</font></h2>" . $sent . $showdeck;
	  }; ?>
			

 <div class="table" >
 <div class="dealer" >
    <?php 
      if ($gameOn) {
        echo '<strong>Dealer\'s Hand</strong><br>';
        echo displayDealerHand($state, $upcard, $dealerHand, $dealerScore);
      }
      else {
	  if ($balance >= $min)
	  {
        echo '<strong>Press Deal to Play</strong>';
		}
		else
		{
		echo '<strong>MIN BET: ' .  $min . ' ' . $currency . ' - MAX BET: ' . $max . ' ' . $currency .'</strong>';
		}

      }
    ?>
 </div>
 <div class="player" >

    <?php 
      if ($gameOn) {
        echo '<br><strong>Your Hand</strong><br>';
        $cards = $userHand->getCards();
        foreach($cards as $card) {
          echo getImage($card);
        }
        echo "<br><font color='white' >You have: ".$userScore."<font><br>";
      }
    ?>
	</div>
 <div class="notes" >  
      <?php 
        if ($gameOn) {
          if (State::isActive($state)) {
            echo "<strong>Game is active...</strong>";
          }
          else {
            switch ($state) {
              case State::USER_BUST:
                echo "<strong>Sorry, you busted!</strong>";
                break;
              case State::DEALER_WIN:
                echo "<strong>Sorry, the dealer's hand beats yours!</strong>";
                break;
              case State::DEALER_BUST:
                echo "<strong>Congratulations, you won!  The dealer busted.</strong>";
                break;
              case State::USER_WIN:
                echo "<strong>Congratulations, you won!  Your hand beats the dealer's.</strong>";
                break;
              case State::PUSH:
                echo "<strong>Push!</strong>";
                break;
              case State::USER_BLACKJACK:
                echo "<strong>Congratulations, you have Blackjack!</strong>";
                break;
              case State::DEALER_BLACKJACK:
                echo "<strong>Sorry, the dealer has Blackjack!</strong>";
                break;
              default:
                echo "<strong>Invalid game state.</strong>";
                break;
            }
          }
        }
        else {
          echo "";
        }
      ?>
	
  </div>
  </div>

     <form method="post" id="submitPlay" action="index.php">
      <input type="submit" name="Deal"  style='height: 25px; width: 50px' value="Deal" <?php if ((!$dealEnabled) || ($balance < $min)) { echo "class='buttondisabled' disabled";} else { echo "class='buttondeal'";}; ?>/><input type="submit" name="Hit" value="Hit"<?php if (!$hitEnabled) { echo "class='buttondisabled' disabled";} else { echo "class='buttondeal'";}; ?>/><input type="submit" name="Stand" value="Stand"<?php if (!$standEnabled) { echo "class='buttondisabled' disabled";} else { echo "class='buttondeal'";}; ?>/><input type="submit" name="Double" value="Double"<?php if (!$doubleEnabled){ echo "class='buttondisabled' disabled";} else { echo "class='buttondeal'";}; ?>/>
      </form>   	 
    <form method="post" id="submitPlay" action="index.php">
	<strong><?php if ((isset($_SESSION['txid'])) && ($balance >= $min)) { if (!$dealEnabled) {echo 'BET Amount:';} else if ($dealEnabled) {echo "<font color='red'>Select Bet:</font>";};};?></strong><br>
	 <?php
	 $select = '';
        if (($wagerEnabled) && ($balance >= $min)) {
        if ($balance >= $max)
         {		
		  define('MAX_WAGER', $max);
		  }
		  else
		  {
		  define('MAX_WAGER', $balance);
		  }
		  $select .= "<select onchange='submit()' name='Wager' class='buttondeal'>";
        }
        else {
		define('MAX_WAGER', $balance);
          $select .= "<select name='Wager' class='buttondisabled' disabled>";
        }
        
        for($i = MIN_WAGER; $i <= MAX_WAGER; $i += WAGER_STEP) {
          if($i == $wallet->getWager()) {
            $select .= '<option selected>';
          }
          else {
            $select .= '<option>';
          }
          $select .= $i;
          $select .= '</option>';
        }
        $select .= '</select>';
        echo $select;
      ?>
    </form>  
	<?php if (isset($_SESSION['txid'])) { echo "<strong>Sha256 - Play Data + TXID: </strong><input type='text' class='rounded2' value='" . $salthash . " 'READONLY ><br>";}; ?>
  	<?php if ((isset($_SESSION['lastdeck'])) && (!isset($_SESSION['wait']))){ echo "<strong>Previous 52 Card Play Data: </strong><input type='text' class='rounded3' value='" . $_SESSION['lastdeck'] . "' READONLY>";}?><br>
  	<div class="cashout" >
	  	      <form method="post" id="submitPlay" action="index.php">
 <input type="submit" name="Reset" value="Cash Out"<?php if ((!$dealEnabled) || ($balance < $min)) { echo "class='buttondisabled' disabled";} else {echo "class='buttondeal'";}; ?> />
    </form>
	</div>
  </center>
</body>
</html>


				<div id="Dialog" title="Cryptojack!"><div id="DialogText"></div></div>		
				</article>
			</div>
			<div class="wrapper wrapper-style2">
			<header>
				<h2>How To Play:</h2>
			</header>
				<article class="container" id="howto">
					<div class="row">
						<div class="12u" >
						<strong>1) Send funds to the displayed <?php echo $coin; ?> address above. These funds are held in your game wallet and can be cashed out instantly.</strong><br>
						
						<strong>2) Once funds are received the game will start automatically.<br>
						3) Select the amount you wish to bet (MIN BET <?php echo $min . " " . $currency;?> - MAX BET <?php echo $max . " " . $currency;?>).<br>
						4) To start press deal.<br>
						5) Once the hand is played and the outcome known any winnings due will be automatically credited back to your game wallet.<br></strong>
					<p><strong><font size='2' color='red'>Note: <i>Make sure your <?php echo $coin; ?> client (wallet) is fully synced before sending your bet!</i></font></strong></p>					
</div>
					</div>
				</article>
			</div>
				<div class="wrapper wrapper-style2">
			<header>
				<h2>Payout Odds:</h2>
			</header>
				<article class="container" id="odds">
					<div class="row">
						<div class="12u" >
						<strong>Cryptojack offers some of the best blackjack odds available and all on a single deck of 52 cards!</strong>
						<p>
						<font size='3' color='red'>Win: </font><strong>Pays 1/1</strong><br>
						<font size='3' color='red'>Blackjack: </font><strong>Pays 3/2</strong><br>
                                <strong>MIN BET <?php echo $min . " " . $currency;?></strong><br>
								<strong>MAX BET <?php echo $max . " " . $currency;?></strong><br>
								</div>
					</div>
				</article>
			</div>
							<div class="wrapper wrapper-style2">
			<header>
				<h2>Fairness:</h2>
			</header>
				<article class="container" id="fair">
					<div class="row">
						<div class="12u" >
						<p>
						<strong>All games are 100% Provably fair and the sha256 hash of the current 52 card deck play data is always displayed prior to the game starting!</strong></p>
						<p><strong>Cryptojack uses the following game system:<br><br>
						First a Single 52 Card Deck is shuffled to produce the Hexidecimal play data like so:</strong><br>
						<font size='3' color='red'>5,2,7,a,3,4,1,2,c,4,7,8,5,8,7,5,d,1,6,6,c,6,4,9,a,8,3,c,8,9,d,6,a,c,7,b,4,2,b,1,9,5,3,b,d,2,1,d,b,a,9,3</font><br>
						<strong>This data is then displayed to the user in the form of a 'sha256' algorithm always using the txid of the <i>first</i> game wallet deposit as the salt, like so: </strong><font size='3' color='red'>Play Data + TXID</font><br>
						<strong>The <i>unhashed</i> play data determines the play order of the following 52 cards from left to right, each number and letter represent a corresponding card using the following key:</strong><br>
						<font size='3' color='red'>ACE=1 - KING=d - QUEEN=c - JACK=b - 10=a - 2to9=2to9</font><br><br>
						<strong>Gameplay:<br>
						The dealer is dealt the first card face down then the player receives the next two cards then finally the dealer receives their second card.<br>
						The dealer ALWAYS stands on both 17 and "soft" 17 (e.g. An initial ace and six).<br>
						Once the 52 cards have been played then the deck is re-shuffled and the previous decks <i>unhashed</i> play data is displayed once the current hand is over.</strong><br><br>
						<strong><font size='2' color='red'>Note: <i>As the game is card value dependent rather than suit dependant the suits are just randomly generated during gameplay, this means it may be possible to see card suit duplicates - <br>but rest assured each 52 card deck consists of only 4 of each card value as proven by the revelead play data and sha256 hash's thereof.
						</i></font></strong></p><br>
											</div>
					</div>
				</article>
			</div>	
							<div class="wrapper wrapper-style2">
			<header>
				<h2>Play Data Converter:</h2>
			</header>
				<article class="container" id="converter">
					<div class="row">
						<div class="12u" >
		<?php
if (isset($_POST['playData']))
{
$_POST['playData'] = trim($_POST['playData']);
$aValid = array(',');
if(ctype_alnum(str_replace($aValid, '', $_POST['playData']))) 
{ 
$input = strlen($_POST['playData']);
if ($input == 103) 
{   
$resultin=($_POST['playData']);
$result=explode(",", $resultin);
foreach($result as $value) {
if ($value == 1)
{
echo "<font color='red'>ACE,</font>";
}
else if ($value == 'a')
{
echo "<font color='red'>10,</font>";
}
else if ($value == 'b')
{
echo "<font color='red'>JACK,</font>";
}
else if ($value == 'c')
{
echo  "<font color='red'>QUEEN,</font>";
}
else if ($value == 'd')
{
echo "<font color='red'>KING,</font>";
}
else
{
echo "<font color='red'>" . $value . ",</font>";
}
}
}
else
{
echo "<font color='red'>Invalid Play Data Entered!</font>";
}
}
else
{
echo "<font color='red'>Invalid Play Data Entered!</font>";
}
}
?>				
						<strong><br>To convert the hexidecimal play data into human readable format please enter it below and hit enter:</strong></br>
<form method="post"  action="index.php#converter" >
	 <input type='text' name='playData' onchange='submit()' class='rounded3' ><br> 
	 </form>
	 
											</div>
					</div>
				</article>
			</div>	
			</body>
			</html>
