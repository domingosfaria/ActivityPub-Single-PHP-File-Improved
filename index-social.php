<?php

	/*
	* Modifications by Domingos Faria.
	*	"This code is not a code of honour... no highly esteemed code is commemorated here... nothing valued is here."
	*	"What is here is dangerous and repulsive to us. This message is a warning about danger."
	*	This is a rudimentary, single-file, low complexity, minimum functionality, ActivityPub server.
	*	For educational purposes only.
	*	The Server produces an Actor who can be followed.
	*	The Actor can send messages to followers.
	*	The message can have linkable URls, hashtags, and mentions.
	*	An image and alt text can be attached to the message.
	*	The Actor can follow, unfollow, block, and unblock remote accounts.
	*	The Server saves logs about requests it receives and sends.
	*	This code is NOT suitable for production use.
	*	SPDX-License-Identifier: AGPL-3.0-or-later
	*	This code is also "licenced" under CRAPL v0 - https://matt.might.net/articles/crapl/
	*	"Any appearance of design in the Program is purely coincidental and should not in any way be mistaken for evidence of thoughtful software construction."
	*	For more information, please re-read.
	*/

	//	Preamble: Set your details here
	//	This is where you set up your account's name and bio.
	//	You also need to provide a public/private keypair.
	//	The posting page is protected with a password that also needs to be set here.

	//	Set up the Actor's information here, or in the .env file
	$env = parse_ini_file('.env');
	//	Edit these:
	$username = rawurlencode( $env["USERNAME"] );	//	Type the @ username that you want. Do not include an "@". 
	$realName = $env["REALNAME"];	//	This is the user's "real" name.
	$summary  = $env["SUMMARY"];	//	This is the bio of your user.

	//	Generate locally or from https://cryptotools.net/rsagen
	//	Newlines must be replaced with "\n"
	$key_private = str_replace('\n', "\n", $env["KEY_PRIVATE"] );
	$key_public  = str_replace('\n', "\n", $env["KEY_PUBLIC"]  );

	//	Password for sending messages
	$password = $env["PASSWORD"];

	/** No need to edit anything below here. But please go exploring! **/

	//	Internal data
	$server   = $_SERVER["SERVER_NAME"];	//	Do not change this!

	//	Some requests require a User-Agent string.
	define( "USERAGENT", "activitypub-single-php-file/0.0" );

	//	Set up where to save logs, posts, and images.
	//	You can change these directories to something more suitable if you like.
	$data = "data";
	$directories = array(
		"inbox"      => "{$data}/inbox",
		"followers"  => "{$data}/followers",
		"following"  => "{$data}/following",
		"logs"       => "{$data}/logs",
		"posts"      => "posts",
		"images"     => "images",
	);
	//	Create the directories if they don't already exist.
	foreach ( $directories as $directory ) {
		if( !is_dir( $directory ) ) { mkdir( $data ); mkdir( $directory ); }
	}

	// Get the information sent to this server
	$input       = file_get_contents( "php://input" );
	$body        = json_decode( $input,true );
	$bodyData    = print_r( $body,     true );
	
	//	If the root has been requested, manually set the path to `/`
	!empty( $_GET["path"] ) ? $path = $_GET["path"] : $path = "/";

	//	Routing:
	//	The .htaccess changes /whatever to /?path=whatever
	//	This runs the function of the path requested.
	switch ( $path ) {
		case ".well-known/webfinger":
			webfinger();   //	Mandatory. Static.
			case ".well-known/atproto-did":
			atproto();			
		case ".well-known/nodeinfo":
			wk_nodeinfo(); //	Optional. Static.	
		case "nodeinfo/2.1":
			nodeinfo();    //	Optional. Static.
			case rawurldecode( $username ):
		case "@" . rawurldecode( $username ):	//	Some software assumes usernames start with an `@`
			username();    //	Mandatory. Static
		case "following":
			following();   //	Mandatory. Can be static or dynamic.
		case "followers":
			followers();   //	Mandatory. Can be static or dynamic.
		case "inbox":
			inbox();       //	Mandatory.
		case "outbox":    
			outbox();      //	Optional. Dynamic.
		case "write":
			write();       //	User interface for writing posts.
		case "action/send":      
			send();        //	API for posting content to the Fediverse.
		//case "action/enviar":      
		//	enviar();	  //	API for posting content to the Fediverse.	
		case "users":
			users();       // User interface for (un)following & (un)blocking an external user.
		case "action/users":
			action_users();// API for following a user.
		case "notifications":
			notifications();// User interface for notifications.
		case "/":
			timeline();// User interface for notifications.
		case "test2":
			test2();// User interface for notifications.
		case "mural":
			mural();// User interface for notifications.		
			case "rss":
				rss();// User interface for notifications.
		case "feed":
			feed();// User interface for feed.
		case "twtxt.txt":
			twtxt();// User interface for notifications.						
		case "read":
			view( "read" );// User interface for reading posts.
		case "timeline":         
			view( "home" );// User interface for seeing what the user has posted.
		default:
			die();
	}

	//	Set the username for BSky accounts
	//	It is looked up with `example.com/.well-known/atproto-did`
	//	See https://atproto.com/specs/handle#handle-resolution
	function atproto() {
		global $atproto;
		header( "Content-Type:text/plain" );
		echo "did:plc:c4se2nnawrskymb3wyt4zlxq";
		die();
	}

	//	The WebFinger Protocol is used to identify accounts.
	//	It is requested with `example.com/.well-known/webfinger?resource=acct:username@example.com`
	//	This server only has one user, so it ignores the query string and always returns the same details.
	function webfinger() {
		global $username, $server;

		$webfinger = array(
			"subject" => "acct:{$username}@{$server}",
 			  "links" => array(
				array(
					 "rel" => "self",
					"type" => "application/activity+json",
					"href" => "https://{$server}/{$username}"
				)
			)
		);
		header( "Content-Type: application/json" );
		echo json_encode( $webfinger );
		die();
	}

	//	User:
	//	Requesting `example.com/username` returns a JSON document with the user's information.
	function username() {
		global $username, $realName, $summary, $server, $key_public;

		$user = array(
			"@context" => [
				"https://www.w3.org/ns/activitystreams",
				"https://w3id.org/security/v1"
			],
			                       "id" => "https://{$server}/{$username}",
			                     "type" => "Person",
			                "following" => "https://{$server}/following",
			                "followers" => "https://{$server}/followers",
			                    "inbox" => "https://{$server}/inbox",
			                   "outbox" => "https://{$server}/outbox",
			        "preferredUsername" =>  rawurldecode( $username ),
			                     "name" => "{$realName}",
			                  "summary" => "{$summary}",
			                      "url" => "https://{$server}/{$username}",
			"manuallyApprovesFollowers" =>  false,
			             "discoverable" =>  true,
			                "published" => "1987-06-07T00:00:01Z",
			"icon" => [
				     "type" => "Image",
				"mediaType" => "image/png",
				      "url" => "https://{$server}/icon.png"
			],
			"image" => [
				     "type" => "Image",
				"mediaType" => "image/png",
				      "url" => "https://{$server}/banner.jpeg"
			],
			"publicKey" => [
				"id"           => "https://{$server}/{$username}#main-key",
				"owner"        => "https://{$server}/{$username}",
				"publicKeyPem" => $key_public
			],
			"attachment" => [
				[
					"type" => "PropertyValue",
					"name" => "Website",
					"value" => "<a href='https://dfaria.eu' target='_blank' rel='nofollow noopener noreferrer me' translate='no'>https://dfaria.eu</a>"
				]
			],
		);
		header( "Content-Type: application/activity+json" );
		echo json_encode( $user );
		die();
	}

	//	Follower / Following:
	// These JSON documents show how many users are following / followers-of this account.
	// The information here is self-attested. So you can lie and use any number you want.
	function following() {
		global $server, $directories;

		//	Get all the files 
		$following_files = glob( $directories["following"] . "/*.json" );
		//	Number of users
		$totalItems = count( $following_files );

		//	Sort users by most recent first
		usort( $following_files, function( $a, $b ) {
			return filemtime($b) - filemtime($a);
		});

		//	Create a list of all accounts being followed
		$items = array();
		foreach ( $following_files as $following_file ) {
			$following = json_decode( file_get_contents( $following_file ), true );
			$items[] = $following["id"];
		}

		$following = array(
			  "@context" => "https://www.w3.org/ns/activitystreams",
			        "id" => "https://{$server}/following",
			      "type" => "Collection",
			"totalItems" => $totalItems,
			     "items" => $items
		);
		header( "Content-Type: application/activity+json" );
		echo json_encode( $following );
		die();
	}
	function followers() {
		global $server, $directories;
		//	The number of followers is self-reported.
		//	You can set this to any number you like.
		
		//	Get all the files 
		$follower_files = glob( $directories["followers"] . "/*.json" );
		//	Number of users
		$totalItems = count( $follower_files );

		//	Sort users by most recent first
		usort( $follower_files, function( $a, $b ) {
			return filemtime($b) - filemtime($a);
		});

		//	Create a list of everyone being followed
		$items = array();
		foreach ( $follower_files as $follower_file ) {
			$following = json_decode( file_get_contents( $follower_file ), true );
			$items[] = $following["id"];
		}

		$followers = array(
			  "@context" => "https://www.w3.org/ns/activitystreams",
			        "id" => "https://{$server}/followers",
			      "type" => "Collection",
			"totalItems" => $totalItems,
			     "items" => $items
		);
		header( "Content-Type: application/activity+json" );
		echo json_encode( $followers );
		die();
	}

	//	Inbox:
	//	The `/inbox` is the main server. It receives all requests. 
	function inbox() {
		global $body, $server, $username, $key_private, $directories;

		//	Get the message, type, and ID
		$inbox_message = $body;
		$inbox_type = $inbox_message["type"];

		//	This inbox only sends responses to follow requests.
		//	A remote server sends the inbox a follow request which is a JSON file saying who they are.
		//	The details of the remote user's server is saved to a file so that future messages can be delivered to the follower.
		//	An accept request is cryptographically signed and POST'd back to the remote server.
		if ( "Follow" == $inbox_type ) { 
			//	Validate HTTP Message Signature
			if ( !verifyHTTPSignature() ) { die(); }

			//	Get the parameters
			$follower_id    = $inbox_message["id"];    //	E.g. https://mastodon.social/(unique id)
			$follower_actor = $inbox_message["actor"]; //	E.g. https://mastodon.social/users/Edent
			
			//	Get the actor's profile as JSON
			$follower_actor_details = getDataFromURl( $follower_actor );

			//	Save the actor's data in `/data/followers/`
			$follower_filename = urlencode( $follower_actor );
			file_put_contents( $directories["followers"] . "/{$follower_filename}.json", json_encode( $follower_actor_details ) );
			
			//	Get the new follower's Inbox
			$follower_inbox = $follower_actor_details["inbox"];

			//	Response Message ID
			//	This isn't used for anything important so could just be a random number
			$guid = uuid();

			//	Create the Accept message to the new follower
			$message = [
				"@context" => "https://www.w3.org/ns/activitystreams",
				"id"       => "https://{$server}/{$guid}",
				"type"     => "Accept",
				"actor"    => "https://{$server}/{$username}",
				"object"   => [
					"@context" => "https://www.w3.org/ns/activitystreams",
					"id"       =>  $follower_id,
					"type"     =>  $inbox_type,
					"actor"    =>  $follower_actor,
					"object"   => "https://{$server}/{$username}",
				]
			];

			//	The Accept is POSTed to the inbox on the server of the user who requested the follow
			sendMessageToSingle( $follower_inbox, $message );
		} else {
			//	Messages to ignore.
			//	Some servers are very chatty. They send lots of irrelevant messages.
			//	Before even bothering to validate them, we can delete them.

			//	This server doesn't handle Add, Remove, or Reject
			//	See https://www.w3.org/wiki/ActivityPub/Primer
			if ( "Add" == $inbox_type || "Remove" == $inbox_type || "Reject" == $inbox_type ) { 
				die(); 
			}

			//	Messages from accounts which aren't being followed.
			//	Some servers send delete messages about users we don't follow.
			//	Lemmy sends messages even after unfollowing or blocking a channel

			//	Get a list of every account we follow
			//	Get all the files 
			$following_files = glob( $directories["following"] . "/*.json");
			
			//	Create a list of all accounts being followed
			$following_ids = array();
			foreach ( $following_files as $following_file ) {
				$following = json_decode( file_get_contents( $following_file ), true );
				$following_ids[] = $following["id"];
			}

			//	Is this from someone we follow?
			in_array( $inbox_message["actor"], $following_ids ) ? $from_following = true: $from_following = false;

			//	Get a list of every account following us
			//	Get all the files 
			$followers_files = glob( $directories["followers"] . "/*.json");
			
			//	Create a list of all accounts being followed
			$followers_ids = array();
			foreach ( $followers_files as $follower_file ) {
				$follower = json_decode( file_get_contents( $follower_file ), true );
				$followers_ids[] = $follower["id"];
			}

			//	Is this from someone following us?
			in_array( $inbox_message["actor"], $followers_ids ) ? $from_follower = true: $from_follower = false;

			//	Has the user has been specifically CC'd?
			if ( isset( $inbox_message["cc"] ) ) {
				if ( is_array( $inbox_message["cc"] ) ) {
					$reply = in_array( "https://{$server}/{$username}", $inbox_message["cc"] );
				} else {
					$reply = ( "https://{$server}/{$username}" === $inbox_message["cc"] );
				}
			} else {
				$reply = false;
			}

			//	As long as one of these is true, the server will process it
			if ( !$reply && !$from_following && !$from_follower ) {
				//	Don't bother processing it at all.
				die();
			}

			//	Validate HTTP Message Signature
			if ( !verifyHTTPSignature() ) { die(); }

			//	If this is an Undo, Delete, or Update message, try to process it
			if ( "Undo" == $inbox_type || "Delete" == $inbox_type || "Update" == $inbox_type ) { 
				undo( $inbox_message ); 
			}
		}
		
		//	If the message is valid, save the message in `/data/inbox/`
		$uuid = uuid( $inbox_message );
		$inbox_filename = $uuid . "." . urlencode( $inbox_type ) . ".json";
		file_put_contents( $directories["inbox"] . "/{$inbox_filename}", json_encode( $inbox_message ) );

		die();
	}

	//	Unique ID:
	// Every message sent should have a unique ID. 
	// This can be anything you like. Some servers use a random number.
	// I prefer a date-sortable string.
	function uuid( $message = null) {
		//	UUIDs that this server *sends* will be [timestamp]-[random]
		//	65e99ab4-5d43-f074-b43e-463f9c5cf05c
		if ( is_null( $message ) ) {
			return sprintf( "%08x-%04x-%04x-%04x-%012x",
				time(),
				mt_rand(0, 0xffff),
				mt_rand(0, 0xffff),
				mt_rand(0, 0x3fff) | 0x8000,
				mt_rand(0, 0xffffffffffff)
			);
		} else {
			//	UUIDs that this server *saves* will be [timestamp]-[hash of message ID]
			//	65eadace-8f434346648f6b96df89dda901c5176b10a6d83961dd3c1ac88b59b2dc327aa4

			//	The message might have its own object
			if ( isset( $message["object"]["id"] ) ) {
				$id = $message["object"]["id"];
			} else {
				$id = $message["id"];
			}

			return sprintf( "%08x", time() ) . "-" . hash( "sha256", $id );
		}
	}

	//	Headers:
	// Every message that your server sends needs to be cryptographically signed with your Private Key.
	// This is a complicated process.
	// Please read https://blog.joinmastodon.org/2018/07/how-to-make-friends-and-verify-requests/ for more information.
	function generate_signed_headers( $message, $host, $path, $method ) {
		global $server, $username, $key_private;
	
		//	Location of the Public Key
		$keyId  = "https://{$server}/{$username}#main-key";

		//	Get the Private Key
		$signer = openssl_get_privatekey( $key_private );

		//	Timestamp this message was sent
		$date   = date( "D, d M Y H:i:s \G\M\T" );

		//	There are subtly different signing requirements for POST and GET.
		if ( "POST" == $method ) {
			//	Encode the message object to JSON
			$message_json = json_encode( $message );
			//	Generate signing variables
			$hash   = hash( "sha256", $message_json, true );
			$digest = base64_encode( $hash );

			//	Sign the path, host, date, and digest
			$stringToSign = "(request-target): post $path\nhost: $host\ndate: $date\ndigest: SHA-256=$digest";
			
			//	The signing function returns the variable $signature
			//	https://www.php.net/manual/en/function.openssl-sign.php
			openssl_sign(
				$stringToSign, 
				$signature, 
				$signer, 
				OPENSSL_ALGO_SHA256
			);
			//	Encode the signature
			$signature_b64 = base64_encode( $signature );

			//	Full signature header
			$signature_header = 'keyId="' . $keyId . '",algorithm="rsa-sha256",headers="(request-target) host date digest",signature="' . $signature_b64 . '"';

			//	Header for POST request
			$headers = array(
				        "Host: {$host}",
				        "Date: {$date}",
				      "Digest: SHA-256={$digest}",
				   "Signature: {$signature_header}",
				"Content-Type: application/activity+json",
				      "Accept: application/activity+json",
			);
		} else if ( "GET" == $method ) {	
			//	Sign the path, host, date - NO DIGEST because there's no message sent.
			$stringToSign = "(request-target): get $path\nhost: $host\ndate: $date";
			
			//	The signing function returns the variable $signature
			//	https://www.php.net/manual/en/function.openssl-sign.php
			openssl_sign(
				$stringToSign, 
				$signature, 
				$signer, 
				OPENSSL_ALGO_SHA256
			);
			//	Encode the signature
			$signature_b64 = base64_encode( $signature );

			//	Full signature header
			$signature_header = 'keyId="' . $keyId . '",algorithm="rsa-sha256",headers="(request-target) host date",signature="' . $signature_b64 . '"';

			//	Header for GET request
			$headers = array(
				        "Host: {$host}",
				        "Date: {$date}",
				   "Signature: {$signature_header}",
				      "Accept: application/activity+json, application/json",
			);
		}

		return $headers;
	}

	// User Interface for Homepage.
	// This creates a basic HTML page. This content appears when someone visits the root of your site.
	function view( $style ) {
		global $password, $username, $server, $realName, $summary, $directories;
		$rawUsername = rawurldecode( $username );

		//	What sort of viewable page is this?
		switch ( $style ) {
			case "home":
				$h1 = "HomePage";
				$directory = "posts";
				break;
			case "read":
				$h1 = "InBox";
				$directory = "inbox";
				break;
		}
		
		//	Counters for followers, following, and posts
		$follower_files  = glob( $directories["followers"] . "/*.json" );
		$totalFollowers  = count( $follower_files );
		$following_files = glob( $directories["following"] . "/*.json" );
		$totalFollowing  = count( $following_files );
		//	Get all posts
		$posts = array_reverse( glob( $directories["posts"] . "/*.json") );
		//	Number of posts
		$totalItems = count( $posts );

  // Check if the user is already authenticated via a cookie
  if (isset($_COOKIE['auth']) && $_COOKIE['auth'] === hash('sha256', $password)) {
	$authenticated = true;
}

// Handle login attempt
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['password'])) {
	if ($_POST['password'] === $password) {
		// Correct password: set cookie and authenticate
		setcookie('auth', hash('sha256', $password), time() + 86400 * 30, "/"); // 30 days
		$authenticated = true;
	} else {
		// Incorrect password: show error message
		$error = "Incorrect password. Please try again.";
	}
}

// If not authenticated, show login form
if (!isset($authenticated)) {
	echo <<<HTML
<!DOCTYPE html>
<html lang="en-GB">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<meta property="og:url" content="https://{$server}">
<meta property="og:type" content="website">
<meta property="og:title" content="Mural">
<meta property="og:description" content="{$summary}">
<meta property="og:image" content="https://{$server}/banner.jpeg">
<title>Social: Mural</title>
<link rel="stylesheet" href="https://social.dfaria.eu/style.css">
<link rel="stylesheet" href="https://use.fontawesome.com/releases/v6.0.0/css/all.css">
</head>
<body>
<main class="h-feed">
	<header>
		<div class="about">
		<p><a href="/"><i class="fa-solid fa-house"></i></a> &emsp;&emsp; <a href="/timeline"><i class="fa-regular fa-comment"></i></a> &emsp;&emsp; <a href="read"><i class="fa-regular fa-comments"></i></a> &emsp;&emsp; <a href="mural"><i class="fa-solid fa-bars-staggered"></i></a> &emsp;&emsp; <a href="write"><i class="fa-regular fa-pen-to-square"></i></a> &emsp;&emsp; <a href="users"><i class="fa-regular fa-user"></i></a> &emsp;&emsp; <a href="notifications"><i class="fa-regular fa-bell"></i></a> &emsp;&emsp; <a href="notifications?logout"><i class="fa-solid fa-arrow-right-to-bracket"></i></a></p>
		</div>
<main>
	<form method="post">
		<br>
		<input type="password" id="password" name="password" required>
		<button type="submit">Login</button>
	</form>
HTML;
	if (isset($error)) {
		echo "<p style='color: red;'>$error</p>";
	}
	echo <<<HTML
</main>
</body>
</html>
HTML;
	die();
}

// Original mural function content starts here
$rawUsername = rawurldecode($username);
	
		//	Show the HTML page
echo <<< HTML
<!DOCTYPE html>
<html lang="en-GB">
	<head>
		<meta charset="UTF-8">
		<meta name="viewport" content="width=device-width, initial-scale=1.0">
		<meta property="og:url" content="https://{$server}">
		<meta property="og:type" content="website">
		<meta property="og:title" content="{$realName}">
		<meta property="og:description" content="{$summary}">
		<meta property="og:image" content="https://{$server}/banner.jpeg">
		<title>Social: {$realName}</title>
		<link rel="stylesheet" href="https://social.dfaria.eu/style.css">
		<link rel="stylesheet" href="https://use.fontawesome.com/releases/v6.0.0/css/all.css">
	</head>
	<body>
		<main class="h-feed">
			<header>
				<div class="banner">
					<!---<img src="banner.jpeg" alt="" class="u-feature"><br>
					<img src="icon.png" alt="icon" class="u-photo">--->
				</div>
				<address>
					<h2>{$realName} <a class="p-nickname u-url" rel="author" href="https://{$server}/{$username}">@{$rawUsername}@{$server}</a></h2>
				</address>
				<!---<p class="p-summary">{$summary}</p>--->
				<div class="about">
				<p><a href="following"><b>{$totalFollowing}</b> Following</a> &emsp;&emsp; <a href="followers"><b>{$totalFollowers}</b> Followers</a> &emsp;&emsp; <a href="outbox"><b>{$totalItems}</b> Posts</a></p>
				<p><a href="/"><i class="fa-solid fa-house"></i></a> &emsp;&emsp; <a href="/timeline"><i class="fa-regular fa-comment"></i></a> &emsp;&emsp; <a href="read"><i class="fa-regular fa-comments"></i></a> &emsp;&emsp; <a href="mural"><i class="fa-solid fa-bars-staggered"></i></a> &emsp;&emsp; <a href="write"><i class="fa-regular fa-pen-to-square"></i></a> &emsp;&emsp; <a href="users"><i class="fa-regular fa-user"></i></a> &emsp;&emsp; <a href="notifications"><i class="fa-regular fa-bell"></i></a> &emsp;&emsp; <a href="notifications?logout"><i class="fa-solid fa-arrow-right-to-bracket"></i></a></p>
				</div>
			</header>
			<ul>
HTML;

// Get all the files in the directory
$message_files = array_reverse(glob($directories[$directory] . "/*.json"));
// The UI will only show 200. However, process the most recent 1,000 files to account for duplicates or updates.
$message_files = array_slice($message_files, 0, 999999);

// Loop through the messages, ensuring correct order
$messages_ordered = [];
foreach ($message_files as $message_file) {
    // Split the filename
    $file_parts = explode(".", $message_file);
    $type = $file_parts[1];

    // Ignore "Undo" or "Delete" messages
    if ("Undo" == $type || "Delete" == $type) {
        continue;
    }

    // Parse JSON and sort messages
    $message = json_decode(file_get_contents($message_file), true);
    if (isset($message["published"])) {
        $published = $message["published"];
    } else {
        $segments = explode("/", explode("-", $message_file ?? "")[0]);
        $published_hexstamp = end($segments);
        $published_time = hexdec($published_hexstamp);
        $published = date("c", $published_time);
    }
    $messages_ordered[$published] = $message;
}

// Sort messages with newest on top
krsort($messages_ordered);

// Define pagination parameters
$messages_per_page = 10;
$current_page = isset($_GET['page']) ? intval($_GET['page']) : 1;
if ($current_page < 1) {
    $current_page = 1;
}
$total_messages = count($messages_ordered);
$total_pages = ceil($total_messages / $messages_per_page);

// Slice messages for current page
$start_index = ($current_page - 1) * $messages_per_page;
$messages_for_page = array_slice($messages_ordered, $start_index, $messages_per_page, true);

// Allowed HTML tags for sanitization
$allowed_elements = ["p", "span", "br", "a", "del", "pre", "code", "em", "strong", "b", "i", "u", "ul", "ol", "li", "blockquote"];

// Display messages for the current page
foreach ($messages_for_page as $published => $message) {
    if (isset($message["object"])) {
        $object = $message["object"];
    } else {
        $object = $message;
    }

    if (isset($object["id"])) {
        $id = $object["id"];
        $publishedDate = new DateTime($published);
        $formattedDate = $publishedDate->format('d-m-Y H:i');
        $publishedHTML = "<a href=\"{$id}\">{$formattedDate}</a>";
    } else {
        $id = "";
        $publishedHTML = $published;
    }
    $timeHTML = "<time datetime=\"{$published}\" class=\"u-url\" rel=\"bookmark\">{$publishedHTML}</time>";

    // Determine the actor
    if (isset($object["attributedTo"])) {
        $actor = $object["attributedTo"];
    } else if (isset($message["actor"])) {
        $actor = $message["actor"];
    } else {
        $actor = "https://example.com/anonymous";
    }
    $actorArray = explode("/", $actor);
    $actorName = end($actorArray);
    $actorServer = parse_url($actor, PHP_URL_HOST);
    $actorUsername = "@{$actorName}@{$actorServer}";
    $actorName = htmlspecialchars(rawurldecode($actorName));
    $actorHTML = "<a href=\"$actor\">@{$actorName}</a>";

    // Determine message type and render content
    $type = $message["type"];
    if ("Create" == $type || "Update" == $type || "Note" == $type) {
        $content = isset($object["content"]) ? $object["content"] : "";
        $content = strip_tags($content, $allowed_elements);

        if (isset($object["inReplyTo"])) {
            $replyToURl = $object["inReplyTo"];
            $replyTo = "in reply to <a href=\"{$replyToURl}\">$replyToURl</a>";
        } else {
            $replyTo = "";
        }

        if (isset($object["summary"])) {
            $summary = strip_tags($object["summary"], $allowed_elements);
            $content = "<details><summary>{$summary}</summary>{$content}</details>";
        }

        // Process attachments
        if (isset($object["attachment"])) {
            foreach ($object["attachment"] as $attachment) {
                if (isset($attachment["mediaType"])) {
                    $mediaURl = $attachment["url"];
                    $mime = $attachment["mediaType"];
                    $mediaType = explode("/", $mime)[0];

                    if ("image" == $mediaType) {
                        $alt = isset($attachment["name"]) ? htmlspecialchars($attachment["name"]) : "";
                        $content .= "<img src='{$mediaURl}' alt='{$alt}'>";
                    } else if ("video" == $mediaType) {
                        $content .= "<video controls><source src='{$mediaURl}' type='{$mime}'></video>";
                    } else if ("audio" == $mediaType) {
                        $content .= "<audio controls src='{$mediaURl}' type='{$mime}'></audio>";
                    }
                }
            }
        }

        $verb = $type == "Create" ? "wrote" : ($type == "Update" ? "updated" : "said");
        $interactHTML = 
            "<a href=\"/write?reply=$id&content=@{$actorName}@{$actorServer} \"><i class='fa-regular fa-comment'></i></a>  " .
            "<a href=\"/write?announce=$id\"><i class='fa-solid fa-retweet'></i></a>  " .
            "<a href=\"/write?like=$id\"><i class='fa-regular fa-heart'></i></a>  ";
        $messageHTML = "{$timeHTML} {$actorHTML} {$verb} {$replyTo}: <blockquote class=\"e-content\">{$content}</blockquote>";

    } else if ("Follow" == $type) {
        $messageHTML = "<mark>{$formattedDate} {$actorHTML} followed you</mark>";
    } else if ("Like" == $type || "Announce" == $type) {
        $object = $message["object"];
        $objectHTML = "<a href=\"$object\">{$object}</a>";
        $action = $type == "Like" ? "liked" : "boosted";
        $messageHTML = "<u style='color:gray;'>{$formattedDate} {$actorHTML} {$action} {$objectHTML}</u>";
        $interactHTML = ""; // No interactions for Like/Announce
    } else {
        continue;
    }

    echo <<< HTML
<li><article class="h-entry">{$messageHTML}<br>{$interactHTML}</article></li>
HTML;
}

// Add pagination navigation
echo '<div class="about"><nav class="pagination">';
if ($current_page > 1) {
    $prev_page = $current_page - 1;
    echo "<a href=\"?page={$prev_page}\" class=\"prev\"><i class='fa-regular fa-circle-left'></i> Previous</a> &emsp;&emsp;";
}
if ($current_page < $total_pages) {
    $next_page = $current_page + 1;
    echo "<a href=\"?page={$next_page}\" class=\"next\">Next <i class='fa-regular fa-circle-right'></i></a>";
}
echo '</nav></div>';

echo <<< HTML
</ul>
		</main>
	</body>	
</html>
HTML;
die();
	}

	
// This creates a UI for notifications
function notifications() {
    global $password, $username, $server, $realName, $summary, $directories;

// Handle logout request
if (isset($_GET['logout'])) {
    setcookie('auth', '', time() - 3600, "/"); // Expire the cookie
    echo <<<HTML
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Logged Out</title>
    <link rel="stylesheet" href="https://social.dfaria.eu/style.css">
</head>
<body>
    <main>
        <h1>You have been logged out</h1>
        <p><a href="/notifications">Click here to log in again</a>.</p>
    </main>
</body>
</html>
HTML;
    die();
}

    // Check if the user is already authenticated via a cookie
    if (isset($_COOKIE['auth']) && $_COOKIE['auth'] === hash('sha256', $password)) {
        $authenticated = true;
    }

    // Handle login attempt
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['password'])) {
        if ($_POST['password'] === $password) {
            // Correct password: set cookie and authenticate
            setcookie('auth', hash('sha256', $password), time() + 86400 * 30, "/"); // 30 days
            $authenticated = true;
        } else {
            // Incorrect password: show error message
            $error = "Incorrect password. Please try again.";
        }
    }

    // If not authenticated, show login form
    if (!isset($authenticated)) {
        echo <<<HTML
<!DOCTYPE html>
<html lang="en-GB">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta property="og:url" content="https://{$server}">
    <meta property="og:type" content="website">
    <meta property="og:title" content="Notifications">
    <meta property="og:description" content="{$summary}">
    <meta property="og:image" content="https://{$server}/banner.jpeg">
    <title>Social: Notifications</title>
    <link rel="stylesheet" href="https://social.dfaria.eu/style.css">
    <link rel="stylesheet" href="https://use.fontawesome.com/releases/v6.0.0/css/all.css">
</head>
<body>
    <main class="h-feed">
        <header>
            <div class="about">
			<p><a href="/"><i class="fa-solid fa-house"></i></a> &emsp;&emsp; <a href="/timeline"><i class="fa-regular fa-comment"></i></a> &emsp;&emsp; <a href="read"><i class="fa-regular fa-comments"></i></a> &emsp;&emsp; <a href="mural"><i class="fa-solid fa-bars-staggered"></i></a> &emsp;&emsp; <a href="write"><i class="fa-regular fa-pen-to-square"></i></a> &emsp;&emsp; <a href="users"><i class="fa-regular fa-user"></i></a> &emsp;&emsp; <a href="notifications"><i class="fa-regular fa-bell"></i></a> &emsp;&emsp; <a href="notifications?logout"><i class="fa-solid fa-arrow-right-to-bracket"></i></a></p>
            </div>
    <main>
        <form method="post">
            <br>
            <input type="password" id="password" name="password" required>
            <button type="submit">Login</button>
        </form>
HTML;
        if (isset($error)) {
            echo "<p style='color: red;'>$error</p>";
        }
        echo <<<HTML
    </main>
</body>
</html>
HTML;
        die();
    }

    // Original notifications function content starts here
    $rawUsername = rawurldecode($username);
        
    echo <<<HTML
<!DOCTYPE html>
<html lang="en-GB">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta property="og:url" content="https://{$server}">
    <meta property="og:type" content="website">
    <meta property="og:title" content="Notifications">
    <meta property="og:description" content="{$summary}">
    <meta property="og:image" content="https://{$server}/banner.jpeg">
    <title>Social: Notifications</title>
    <link rel="stylesheet" href="https://social.dfaria.eu/style.css">
    <link rel="stylesheet" href="https://use.fontawesome.com/releases/v6.0.0/css/all.css">
</head>
<body>
    <main class="h-feed">
        <header>
            <div class="about">
			<p><a href="/"><i class="fa-solid fa-house"></i></a> &emsp;&emsp; <a href="/timeline"><i class="fa-regular fa-comment"></i></a> &emsp;&emsp; <a href="read"><i class="fa-regular fa-comments"></i></a> &emsp;&emsp; <a href="mural"><i class="fa-solid fa-bars-staggered"></i></a> &emsp;&emsp; <a href="write"><i class="fa-regular fa-pen-to-square"></i></a> &emsp;&emsp; <a href="users"><i class="fa-regular fa-user"></i></a> &emsp;&emsp; <a href="notifications"><i class="fa-regular fa-bell"></i></a> &emsp;&emsp; <a href="notifications?logout"><i class="fa-solid fa-arrow-right-to-bracket"></i></a></p>
            </div>
        </header>
        <ul>
HTML;

        // Get all the files in the directory
        $directory = "inbox";
        $message_files = array_reverse(glob($directories[$directory] . "/*.json"));

        // Limit to the most recent 1,000 messages
        $message_files = array_slice($message_files, 0, 999999);

        // Process messages
        $messages_ordered = [];
        foreach ($message_files as $message_file) {
            $file_parts = explode(".", $message_file);
            $type = $file_parts[1];

            // Ignore "Undo" and "Delete" types
            if ("Undo" == $type || "Delete" == $type) {
                continue;
            }

            $message = json_decode(file_get_contents($message_file), true);

            if (isset($message["published"])) {
                $published = $message["published"];
            } else {
                $segments = explode("/", explode("-", $message_file ?? "")[0]);
                $published_hexstamp = end($segments);
                $published_time = hexdec($published_hexstamp);
                $published = date("c", $published_time);
            }

            $messages_ordered[$published] = $message;
        }

        krsort($messages_ordered);
        $messages_ordered = array_slice($messages_ordered, 0, 999999);

        // Allowed HTML tags
        $allowed_elements = ["p", "span", "br", "a", "del", "pre", "code", "em", "strong", "b", "i", "u", "ul", "ol", "li", "blockquote"];

        // Display messages
        foreach ($messages_ordered as $published => $message) {
            if (isset($message["object"])) {
                $object = $message["object"];
            } else {
                $object = $message;
            }

            if (isset($object["id"])) {
                $id = $object["id"];
                $publishedDate = new DateTime($published);
                $formattedDate = $publishedDate->format('d-m-Y H:i');
                $publishedHTML = "<a href=\"{$id}\">{$formattedDate}</a>";
            } else {
                $id = "";
                $publishedHTML = $published;
            }

            $timeHTML = "<time datetime=\"{$published}\" class=\"u-url\" rel=\"bookmark\">{$publishedHTML}</time>";

            if (isset($object["attributedTo"])) {
                $actor = $object["attributedTo"];
            } else if (isset($message["actor"])) {
                $actor = $message["actor"];
            } else {
                $actor = "https://example.com/anonymous";
            }

            $actorArray = explode("/", $actor);
            $actorName = end($actorArray);
            $actorServer = parse_url($actor, PHP_URL_HOST);
            $actorUsername = "@{$actorName}@{$actorServer}";
            $actorName = htmlspecialchars(rawurldecode($actorName));
            $actorHTML = "<a href=\"$actor\">@{$actorName}</a>";

            $type = $message["type"];
            if ($type === "Create" || $type === "Note" || $type === "Update") {
                if ($type === "Note") {
                    $content = $message["content"];
                } else if (isset($object["content"])) {
                    $content = $object["content"];
                } else {
                    htmlspecialchars(print_r($object, true));
                }

                $content = strip_tags($content, $allowed_elements);

                if (isset($object["inReplyTo"])) {
                    $replyToURL = $object["inReplyTo"];
                    $replyTo = "in reply to <a href=\"{$replyToURL}\">$replyToURL</a>";
                } else {
                    $replyTo = "";
                }

                if (isset($object["cc"]) && is_array($object["cc"])) {
                    $reply = in_array("https://{$server}/{$username}", $object["cc"]);
                } else {
                    $reply = false;
                }

                if ($reply) {
                    $interactHTML = "<a href=\"/write?reply=$id&content=@{$actorName}@{$actorServer} \"><i class='fa-regular fa-comment'></i></a>  " .
                        "<a href=\"/write?announce=$id\"><i class='fa-solid fa-retweet'></i></a>  " .
                        "<a href=\"/write?like=$id\"><i class='fa-regular fa-heart'></i></a>  ";
                    $messageHTML = "<mark>{$timeHTML} {$actorHTML} wrote {$replyTo}:</mark> <blockquote class=\"e-content\">{$content}</blockquote>";
                    echo "<li><article class=\"h-entry\">{$messageHTML}<br>{$interactHTML}</article></li>";
                }
            } else if ("Like" == $type) {
                $object = $message["object"];
                $objectHTML = "<a href=\"$object\">{$object}</a>";
                $messageHTML = "<u style='color:gray;'>{$formattedDate} {$actorHTML} liked {$objectHTML}</u>";
                echo "<li><article class=\"h-entry\">{$messageHTML}</article></li>";
            }
        }
echo <<< HTML
        </ul>
    </main>
</body>
HTML;
	die();
}

//	This creates a UI for timeline
function timeline() {
	global $username, $server, $realName, $summary, $directories;
	$rawUsername = rawurldecode( $username );
	//	Counters for followers, following, and posts
	$follower_files  = glob( $directories["followers"] . "/*.json" );
	$totalFollowers  = count( $follower_files );
	$following_files = glob( $directories["following"] . "/*.json" );
	$totalFollowing  = count( $following_files );
	//	Get all posts
	$posts = array_reverse( glob( $directories["posts"] . "/*.json") );
	//	Number of posts
	$totalItems = count( $posts );


	//	Show the HTML page
echo <<< HTML
<!DOCTYPE html>
<html lang="en-GB">
<head>
	<meta charset="UTF-8">
	<meta name="viewport" content="width=device-width, initial-scale=1.0">
	<meta property="og:url" content="https://{$server}">
	<meta property="og:type" content="website">
	<meta property="og:title" content="{$realName}">
	<meta property="og:description" content="{$summary}">
	<meta property="og:image" content="https://{$server}/banner.jpeg">
	<title>Social: {$realName}</title>
	<link rel="stylesheet" href="https://social.dfaria.eu/style.css">
	<link rel="stylesheet" href="https://use.fontawesome.com/releases/v6.0.0/css/all.css">
</head>
<body>
	<main class="h-feed">
		<header>
			<div class="banner">
				<!---<img src="banner.jpeg" alt="" class="u-feature"><br>
				<img src="icon.png" alt="icon" class="u-photo">--->
			</div>
			<address>
				<h2>{$realName} <a class="p-nickname u-url" rel="author" href="https://{$server}/{$username}">@{$rawUsername}@{$server}</a></h2>
			</address>
			<!---<p class="p-summary">{$summary}</p>--->
			<div class="about">
			<p><a href="following"><b>{$totalFollowing}</b> Following</a> &emsp;&emsp; <a href="followers"><b>{$totalFollowers}</b> Followers</a> &emsp;&emsp; <a href="outbox"><b>{$totalItems}</b> Posts</a></p>
			<p><a href="/"><i class="fa-solid fa-house"></i></a> &emsp;&emsp; <a href="/timeline"><i class="fa-regular fa-comment"></i></a> &emsp;&emsp; <a href="read"><i class="fa-regular fa-comments"></i></a> &emsp;&emsp; <a href="mural"><i class="fa-solid fa-bars-staggered"></i></a> &emsp;&emsp; <a href="write"><i class="fa-regular fa-pen-to-square"></i></a> &emsp;&emsp; <a href="users"><i class="fa-regular fa-user"></i></a> &emsp;&emsp; <a href="notifications"><i class="fa-regular fa-bell"></i></a> &emsp;&emsp; <a href="notifications?logout"><i class="fa-solid fa-arrow-right-to-bracket"></i></a></p>
			</div>
		</header>
		<ul>
HTML;

// Defina a variável $data com o caminho onde os dados estão armazenados
$data = "data";  // O diretório onde estão os dados (aqui está definido como 'data', mas pode ser alterado conforme necessário)

// Estrutura dos diretórios onde os arquivos .json estão armazenados
$directories = array(
    "posts"      => "posts",              // Diretório posts
);

// Defina os diretórios que você deseja usar (por exemplo, "inbox" e "posts")
$directories_to_use = array("posts");

// Inicialize um array vazio para armazenar os arquivos de ambos os diretórios
$message_files = array();

// Obtenha todos os arquivos .json de cada diretório
foreach ($directories_to_use as $directory) {
    $message_files = array_merge($message_files, glob($directories[$directory] . "/*.json"));
}

// Ordene os arquivos em ordem decrescente de data (mais recentes primeiro)
$message_files = array_reverse($message_files);

// Pegue os 1000 arquivos mais recentes
$message_files = array_slice($message_files, 0, 999999);

// Loop through the messages, ensuring correct order
$messages_ordered = [];
foreach ($message_files as $message_file) {
    // Split the filename
    $file_parts = explode(".", $message_file);
    $type = $file_parts[1];

    // Ignore "Undo" or "Delete" messages
    if ("Undo" == $type || "Delete" == $type) {
        continue;
    }

    // Parse JSON and sort messages
    $message = json_decode(file_get_contents($message_file), true);
    if (isset($message["published"])) {
        $published = $message["published"];
    } else {
        $segments = explode("/", explode("-", $message_file ?? "")[0]);
        $published_hexstamp = end($segments);
        $published_time = hexdec($published_hexstamp);
        $published = date("c", $published_time);
    }
    $messages_ordered[$published] = $message;
}

// Sort messages with newest on top
krsort($messages_ordered);

// Define pagination parameters
$messages_per_page = 10;
$current_page = isset($_GET['page']) ? intval($_GET['page']) : 1;
if ($current_page < 1) {
    $current_page = 1;
}
$total_messages = count($messages_ordered);
$total_pages = ceil($total_messages / $messages_per_page);

// Slice messages for current page
$start_index = ($current_page - 1) * $messages_per_page;
$messages_for_page = array_slice($messages_ordered, $start_index, $messages_per_page, true);

// Allowed HTML tags for sanitization
$allowed_elements = ["p", "span", "br", "a", "del", "pre", "code", "em", "strong", "b", "i", "u", "ul", "ol", "li", "blockquote"];

// Filtrar mensagens válidas
$filtered_messages = [];
foreach ($messages_ordered as $published => $message) {
    if (isset($message["object"])) {
        $object = $message["object"];
    } else {
        $object = $message;
    }

    // Ignorar mensagens que são respostas (inReplyTo não é null)
    if (isset($object["inReplyTo"]) && $object["inReplyTo"] !== null) {
        continue;
    }

    // Ignorar mensagens do tipo "Like" e "Announce"
    if (isset($message["type"]) && in_array($message["type"], ["Like", "Announce"])) {
        continue;
    }

    // Adicionar mensagem ao array filtrado
    $filtered_messages[$published] = $message;
}

// Atualizar o número total de mensagens e páginas
$total_messages = count($filtered_messages);
$total_pages = max(ceil($total_messages / $messages_per_page), 1);

if ($current_page > $total_pages) {
    $current_page = $total_pages;
}

// Fatiar mensagens para a página atual
$start_index = ($current_page - 1) * $messages_per_page;
$messages_for_page = array_slice($filtered_messages, $start_index, $messages_per_page, true);

// Exibir mensagens filtradas
foreach ($messages_for_page as $published => $message) {
    if (isset($message["object"])) {
        $object = $message["object"];
    } else {
        $object = $message;
    }

    if (isset($object["id"])) {
        $id = $object["id"];
        $publishedDate = new DateTime($published);
        $formattedDate = $publishedDate->format('d-m-Y H:i');
        $publishedHTML = "<a href=\"{$id}\">{$formattedDate}</a>";
    } else {
        $id = "";
        $publishedHTML = $published;
    }

    $timeHTML = "<time datetime=\"{$published}\" class=\"u-url\" rel=\"bookmark\">{$publishedHTML}</time>";

    // Determine the actor
    if (isset($object["attributedTo"])) {
        $actor = $object["attributedTo"];
    } else if (isset($message["actor"])) {
        $actor = $message["actor"];
    } else {
        $actor = "https://example.com/anonymous";
    }
    $actorArray = explode("/", $actor);
    $actorName = end($actorArray);
    $actorServer = parse_url($actor, PHP_URL_HOST);
    $actorUsername = "@{$actorName}@{$actorServer}";
    $actorName = htmlspecialchars(rawurldecode($actorName));
    $actorHTML = "<a href=\"$actor\">@{$actorName}</a>";

    // Determinar o conteúdo
    $type = $message["type"];
    if (in_array($type, ["Create", "Update", "Note"])) {
        $content = isset($object["content"]) ? strip_tags($object["content"], $allowed_elements) : "";

        // Processar anexos
        if (isset($object["attachment"])) {
            foreach ($object["attachment"] as $attachment) {
                if (isset($attachment["mediaType"])) {
                    $mediaURl = $attachment["url"];
                    $mime = $attachment["mediaType"];
                    $mediaType = explode("/", $mime)[0];

                    if ("image" == $mediaType) {
                        $alt = isset($attachment["name"]) ? htmlspecialchars($attachment["name"]) : "";
                        $content .= "<img src='{$mediaURl}' alt='{$alt}'>";
                    } else if ("video" == $mediaType) {
                        $content .= "<video controls><source src='{$mediaURl}' type='{$mime}'></video>";
                    } else if ("audio" == $mediaType) {
                        $content .= "<audio controls src='{$mediaURl}' type='{$mime}'></audio>";
                    }
                }
            }
        }

        // Botões de interação
        $interactHTML = 
            "<a href=\"/write?reply=$id&content=@{$actorName}@{$actorServer} \"><i class='fa-regular fa-comment'></i></a>  " .
            "<a href=\"/write?announce=$id\"><i class='fa-solid fa-retweet'></i></a>  " .
            "<a href=\"/write?like=$id\"><i class='fa-regular fa-heart'></i></a>  ";

        $verb = $type == "Create" ? "wrote" : ($type == "Update" ? "updated" : "said");
        $messageHTML = "{$timeHTML} {$actorHTML} {$verb}: <blockquote class=\"e-content\">{$content}</blockquote>";
    } else {
        continue; // Ignore outros tipos de mensagem
    }

    echo <<< HTML
<li><article class="h-entry">{$messageHTML}<br>{$interactHTML}</article></li>
HTML;
}

// Exibir navegação com símbolos de "Anterior" e "Próxima"
echo "<div class='about'><nav class='pagination'>";
if ($current_page > 1) {
    $prev_page = $current_page - 1;
    echo "<a href='?page={$prev_page}'><i class='fa-regular fa-circle-left'></i> Previous</a> &emsp;&emsp;";
}
if ($current_page < $total_pages) {
    $next_page = $current_page + 1;
    echo "<a href='?page={$next_page}'>Next <i class='fa-regular fa-circle-right'></i></a>";
}
echo "</nav></div>";

echo <<< HTML
</ul>
		</main>
	</body>	
</html>
HTML;
	die();
}

//	This creates a UI for mural
function mural() {
	global $password, $username, $server, $realName, $summary, $directories;
	$rawUsername = rawurldecode( $username );
	//	Counters for followers, following, and posts
	$follower_files  = glob( $directories["followers"] . "/*.json" );
	$totalFollowers  = count( $follower_files );
	$following_files = glob( $directories["following"] . "/*.json" );
	$totalFollowing  = count( $following_files );
	//	Get all posts
	$posts = array_reverse( glob( $directories["posts"] . "/*.json") );
	//	Number of posts
	$totalItems = count( $posts );

// Check if the user is already authenticated via a cookie
  if (isset($_COOKIE['auth']) && $_COOKIE['auth'] === hash('sha256', $password)) {
	$authenticated = true;
}

// Handle login attempt
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['password'])) {
	if ($_POST['password'] === $password) {
		// Correct password: set cookie and authenticate
		setcookie('auth', hash('sha256', $password), time() + 86400 * 30, "/"); // 30 days
		$authenticated = true;
	} else {
		// Incorrect password: show error message
		$error = "Incorrect password. Please try again.";
	}
}

// If not authenticated, show login form
if (!isset($authenticated)) {
	echo <<<HTML
<!DOCTYPE html>
<html lang="en-GB">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<meta property="og:url" content="https://{$server}">
<meta property="og:type" content="website">
<meta property="og:title" content="mural">
<meta property="og:description" content="{$summary}">
<meta property="og:image" content="https://{$server}/banner.jpeg">
<title>Social: Mural</title>
<link rel="stylesheet" href="https://social.dfaria.eu/style.css">
<link rel="stylesheet" href="https://use.fontawesome.com/releases/v6.0.0/css/all.css">
</head>
<body>
<main class="h-feed">
	<header>
		<div class="about">
		<p><a href="/"><i class="fa-solid fa-house"></i></a> &emsp;&emsp; <a href="/timeline"><i class="fa-regular fa-comment"></i></a> &emsp;&emsp; <a href="read"><i class="fa-regular fa-comments"></i></a> &emsp;&emsp; <a href="mural"><i class="fa-solid fa-bars-staggered"></i></a> &emsp;&emsp; <a href="write"><i class="fa-regular fa-pen-to-square"></i></a> &emsp;&emsp; <a href="users"><i class="fa-regular fa-user"></i></a> &emsp;&emsp; <a href="notifications"><i class="fa-regular fa-bell"></i></a> &emsp;&emsp; <a href="notifications?logout"><i class="fa-solid fa-arrow-right-to-bracket"></i></a></p>
		</div>
<main>
	<form method="post">
		<br>
		<input type="password" id="password" name="password" required>
		<button type="submit">Login</button>
	</form>
HTML;
	if (isset($error)) {
		echo "<p style='color: red;'>$error</p>";
	}
	echo <<<HTML
</main>
</body>
</html>
HTML;
	die();
}

// Original mural function content starts here
$rawUsername = rawurldecode($username);
	
	//	Show the HTML page
echo <<< HTML
<!DOCTYPE html>
<html lang="en-GB">
<head>
	<meta charset="UTF-8">
	<meta name="viewport" content="width=device-width, initial-scale=1.0">
	<meta property="og:url" content="https://{$server}">
	<meta property="og:type" content="website">
	<meta property="og:title" content="{$realName}">
	<meta property="og:description" content="{$summary}">
	<meta property="og:image" content="https://{$server}/banner.jpeg">
	<title>Social: {$realName}</title>
	<link rel="stylesheet" href="https://social.dfaria.eu/style.css">
	<link rel="stylesheet" href="https://use.fontawesome.com/releases/v6.0.0/css/all.css">
</head>
<body>
	<main class="h-feed">
		<header>
			<div class="banner">
				<!---<img src="banner.jpeg" alt="" class="u-feature"><br>
				<img src="icon.png" alt="icon" class="u-photo">--->
			</div>
			<address>
				<h2>{$realName} <a class="p-nickname u-url" rel="author" href="https://{$server}/{$username}">@{$rawUsername}@{$server}</a></h2>
			</address>
			<!---<p class="p-summary">{$summary}</p>--->
			<div class="about">
			<p><a href="following"><b>{$totalFollowing}</b> Following</a> &emsp;&emsp; <a href="followers"><b>{$totalFollowers}</b> Followers</a> &emsp;&emsp; <a href="outbox"><b>{$totalItems}</b> Posts</a></p>
			<p><a href="/"><i class="fa-solid fa-house"></i></a> &emsp;&emsp; <a href="/timeline"><i class="fa-regular fa-comment"></i></a> &emsp;&emsp; <a href="read"><i class="fa-regular fa-comments"></i></a> &emsp;&emsp; <a href="mural"><i class="fa-solid fa-bars-staggered"></i></a> &emsp;&emsp; <a href="write"><i class="fa-regular fa-pen-to-square"></i></a> &emsp;&emsp; <a href="users"><i class="fa-regular fa-user"></i></a> &emsp;&emsp; <a href="notifications"><i class="fa-regular fa-bell"></i></a> &emsp;&emsp; <a href="notifications?logout"><i class="fa-solid fa-arrow-right-to-bracket"></i></a></p>
			</div>
		</header>
		<ul>
HTML;

// Defina a variável $data com o caminho onde os dados estão armazenados
$data = "data";  // O diretório onde estão os dados (aqui está definido como 'data', mas pode ser alterado conforme necessário)

// Estrutura dos diretórios onde os arquivos .json estão armazenados
$directories = array(
    "inbox"      => "{$data}/inbox",      // Diretório inbox
    "followers"  => "{$data}/followers",  // Diretório followers
    "following"  => "{$data}/following",  // Diretório following
    "logs"       => "{$data}/logs",       // Diretório logs
    "posts"      => "posts",              // Diretório posts
    "images"     => "images",             // Diretório images
);

// Defina os diretórios que você deseja usar (por exemplo, "inbox" e "posts")
$directories_to_use = array("inbox", "posts");

// Inicialize um array vazio para armazenar os arquivos de ambos os diretórios
$message_files = array();

// Obtenha todos os arquivos .json de cada diretório
foreach ($directories_to_use as $directory) {
    $message_files = array_merge($message_files, glob($directories[$directory] . "/*.json"));
}

// Ordene os arquivos em ordem decrescente de data (mais recentes primeiro)
$message_files = array_reverse($message_files);

// Pegue os 1000 arquivos mais recentes
$message_files = array_slice($message_files, 0, 999999);

// Loop through the messages, ensuring correct order
$messages_ordered = [];
foreach ($message_files as $message_file) {
    // Split the filename
    $file_parts = explode(".", $message_file);
    $type = $file_parts[1];

    // Ignore "Undo" or "Delete" messages
    if ("Undo" == $type || "Delete" == $type) {
        continue;
    }

    // Parse JSON and sort messages
    $message = json_decode(file_get_contents($message_file), true);
    if (isset($message["published"])) {
        $published = $message["published"];
    } else {
        $segments = explode("/", explode("-", $message_file ?? "")[0]);
        $published_hexstamp = end($segments);
        $published_time = hexdec($published_hexstamp);
        $published = date("c", $published_time);
    }
    $messages_ordered[$published] = $message;
}

// Sort messages with newest on top
krsort($messages_ordered);

// Define pagination parameters
$messages_per_page = 10;
$current_page = isset($_GET['page']) ? intval($_GET['page']) : 1;
if ($current_page < 1) {
    $current_page = 1;
}
$total_messages = count($messages_ordered);
$total_pages = ceil($total_messages / $messages_per_page);

// Slice messages for current page
$start_index = ($current_page - 1) * $messages_per_page;
$messages_for_page = array_slice($messages_ordered, $start_index, $messages_per_page, true);

// Allowed HTML tags for sanitization
$allowed_elements = ["p", "span", "br", "a", "del", "pre", "code", "em", "strong", "b", "i", "u", "ul", "ol", "li", "blockquote"];

// Filtrar mensagens válidas
$filtered_messages = [];
foreach ($messages_ordered as $published => $message) {
    if (isset($message["object"])) {
        $object = $message["object"];
    } else {
        $object = $message;
    }

    // Ignorar mensagens que são respostas (inReplyTo não é null)
    if (isset($object["inReplyTo"]) && $object["inReplyTo"] !== null) {
        continue;
    }

    // Ignorar mensagens do tipo "Like" e "Announce"
    if (isset($message["type"]) && in_array($message["type"], ["Like", "Announce"])) {
        continue;
    }

    // Adicionar mensagem ao array filtrado
    $filtered_messages[$published] = $message;
}

// Atualizar o número total de mensagens e páginas
$total_messages = count($filtered_messages);
$total_pages = max(ceil($total_messages / $messages_per_page), 1);

if ($current_page > $total_pages) {
    $current_page = $total_pages;
}

// Fatiar mensagens para a página atual
$start_index = ($current_page - 1) * $messages_per_page;
$messages_for_page = array_slice($filtered_messages, $start_index, $messages_per_page, true);

// Exibir mensagens filtradas
foreach ($messages_for_page as $published => $message) {
    if (isset($message["object"])) {
        $object = $message["object"];
    } else {
        $object = $message;
    }

    if (isset($object["id"])) {
        $id = $object["id"];
        $publishedDate = new DateTime($published);
        $formattedDate = $publishedDate->format('d-m-Y H:i');
        $publishedHTML = "<a href=\"{$id}\">{$formattedDate}</a>";
    } else {
        $id = "";
        $publishedHTML = $published;
    }

    $timeHTML = "<time datetime=\"{$published}\" class=\"u-url\" rel=\"bookmark\">{$publishedHTML}</time>";

    // Determine the actor
    if (isset($object["attributedTo"])) {
        $actor = $object["attributedTo"];
    } else if (isset($message["actor"])) {
        $actor = $message["actor"];
    } else {
        $actor = "https://example.com/anonymous";
    }
    $actorArray = explode("/", $actor);
    $actorName = end($actorArray);
    $actorServer = parse_url($actor, PHP_URL_HOST);
    $actorUsername = "@{$actorName}@{$actorServer}";
    $actorName = htmlspecialchars(rawurldecode($actorName));
    $actorHTML = "<a href=\"$actor\">@{$actorName}</a>";

    // Determinar o conteúdo
    $type = $message["type"];
    if (in_array($type, ["Create", "Update", "Note"])) {
        $content = isset($object["content"]) ? strip_tags($object["content"], $allowed_elements) : "";

        // Processar anexos
        if (isset($object["attachment"])) {
            foreach ($object["attachment"] as $attachment) {
                if (isset($attachment["mediaType"])) {
                    $mediaURl = $attachment["url"];
                    $mime = $attachment["mediaType"];
                    $mediaType = explode("/", $mime)[0];

                    if ("image" == $mediaType) {
                        $alt = isset($attachment["name"]) ? htmlspecialchars($attachment["name"]) : "";
                        $content .= "<img src='{$mediaURl}' alt='{$alt}'>";
                    } else if ("video" == $mediaType) {
                        $content .= "<video controls><source src='{$mediaURl}' type='{$mime}'></video>";
                    } else if ("audio" == $mediaType) {
                        $content .= "<audio controls src='{$mediaURl}' type='{$mime}'></audio>";
                    }
                }
            }
        }

        // Botões de interação
        $interactHTML = 
            "<a href=\"/write?reply=$id&content=@{$actorName}@{$actorServer} \"><i class='fa-regular fa-comment'></i></a>  " .
            "<a href=\"/write?announce=$id\"><i class='fa-solid fa-retweet'></i></a>  " .
            "<a href=\"/write?like=$id\"><i class='fa-regular fa-heart'></i></a>  ";

        $verb = $type == "Create" ? "wrote" : ($type == "Update" ? "updated" : "said");
        $messageHTML = "{$timeHTML} {$actorHTML} {$verb}: <blockquote class=\"e-content\">{$content}</blockquote>";
    } else {
        continue; // Ignore outros tipos de mensagem
    }

    echo <<< HTML
<li><article class="h-entry">{$messageHTML}<br>{$interactHTML}</article></li>
HTML;
}

// Exibir navegação com símbolos de "Anterior" e "Próxima"
echo "<div class='about'><nav class='pagination'>";
if ($current_page > 1) {
    $prev_page = $current_page - 1;
    echo "<a href='?page={$prev_page}'><i class='fa-regular fa-circle-left'></i> Previous</a> &emsp;&emsp;";
}
if ($current_page < $total_pages) {
    $next_page = $current_page + 1;
    echo "<a href='?page={$next_page}'>Next <i class='fa-regular fa-circle-right'></i></a>";
}
echo "</nav></div>";

echo <<< HTML
</ul>
		</main>
	</body>	
</html>
HTML;
	die();
}

//	This creates a UI for rss
function rss() {
// Defina a variável $data com o caminho onde os dados estão armazenados
$data = "data";  // O diretório onde estão os dados (aqui está definido como 'data', mas pode ser alterado conforme necessário)

// Estrutura dos diretórios onde os arquivos .json estão armazenados
$directories = array(
    "inbox"      => "{$data}/inbox",      // Diretório inbox
    "followers"  => "{$data}/followers",  // Diretório followers
    "following"  => "{$data}/following",  // Diretório following
    "logs"       => "{$data}/logs",       // Diretório logs
    "posts"      => "posts",              // Diretório posts
    "images"     => "images",             // Diretório images
);

// Defina os diretórios que você deseja usar (por exemplo, "inbox" e "posts")
$directories_to_use = array("inbox", "posts");

// Inicialize um array vazio para armazenar os arquivos de ambos os diretórios
$message_files = array();

// Obtenha todos os arquivos .json de cada diretório
foreach ($directories_to_use as $directory) {
    $message_files = array_merge($message_files, glob($directories[$directory] . "/*.json"));
}

// Ordene os arquivos em ordem decrescente de data (mais recentes primeiro)
$message_files = array_reverse($message_files);

// Pegue os 1000 arquivos mais recentes
$message_files = array_slice($message_files, 0, 999999);

// Inicialize o array para as mensagens ordenadas
$messages_ordered = [];
foreach ($message_files as $message_file) {
    // Divida o nome do arquivo para pegar o tipo da mensagem
    $file_parts = explode(".", $message_file);
    $type = $file_parts[1];

    // Ignore mensagens de tipo "Undo" ou "Delete"
    if ("Undo" == $type || "Delete" == $type) {
        continue;
    }

    // Obtenha o conteúdo do arquivo JSON
    $message = json_decode(file_get_contents($message_file), true);

    // Verificar se a mensagem é uma resposta (inReplyTo não é null)
    if (isset($message["object"])) {
        $object = $message["object"];
    } else {
        $object = $message;
    }
    
    // Pular mensagens que são respostas (inReplyTo não é null)
    if (isset($object["inReplyTo"]) && $object["inReplyTo"] !== null) {
        continue;
    }

    // Verificar se o conteúdo está disponível
    if (!isset($object["content"]) || empty($object["content"])) {
        continue;
    }

    // Ordenar mensagens por data de publicação
    if (isset($message["published"])) {
        $published = $message["published"];
    } else {
        // Caso não haja data "published", use o timestamp do nome do arquivo
        $segments = explode("/", explode("-", $message_file ?? "")[0]);
        $published_hexstamp = end($segments);
        $published_time = hexdec($published_hexstamp);
        $published = date("c", $published_time);
    }

    // Coloque as mensagens no array, com chave sendo o timestamp
    $messages_ordered[$published] = $message;
}

// Ordenar as mensagens com as mais recentes primeiro
krsort($messages_ordered);

// Pegue as 200 mensagens mais recentes
$messages_ordered = array_slice($messages_ordered, 0, 50);

// Cabeçalho do RSS
header('Content-type: text/xml');
echo '<rss version="2.0">';
echo '<channel>';
echo '<title>Domingos Faria | Activitypub</title>';
echo '<link>https://social.dfaria.eu/feed.php</link>';
echo '<description>Domingos Faria Social Network</description>';
echo '<language>PT-pt</language>';

// Permitir alguns elementos HTML seguros
$allowed_elements = ["p", "span", "br", "a", "del", "pre", "code", "em", "strong", "b", "i", "u", "ul", "ol", "li", "blockquote"];

// Loop através das mensagens ordenadas e adicionar cada uma ao RSS
foreach ($messages_ordered as $published => $message) {
    // Verificar a mensagem e obter o autor e conteúdo
    if (isset($message["object"])) {
        $object = $message["object"];
    } else {
        $object = $message;
    }

    // Verificar se a mensagem tem um ID
    $id = isset($object["id"]) ? $object["id"] : "";
    $publishedDate = new DateTime($published);
    $formattedDate = $publishedDate->format('D, d M Y H:i:s T');

    // Conteúdo da mensagem
    $content = $object["content"]; // Removido o fallback para "Conteúdo indisponível"
    $content = strip_tags($content, implode(",", $allowed_elements));
    // Transformar URLs em links
    $content = preg_replace_callback('#(https?://[^\s]+)#', function($matches) {
        return ' ' . $matches[0] . ' ';
    }, $content);

    // Verificar se a mensagem tem imagens anexadas
    if (isset($object["attachment"])) {
        foreach ($object["attachment"] as $attachment) {
            // Verifique se é uma imagem e adicione ao conteúdo
            if (isset($attachment["mediaType"]) && strpos($attachment["mediaType"], "image") === 0) {
                $mediaUrl = $attachment["url"];
                $alt = isset($attachment["name"]) ? htmlspecialchars($attachment["name"]) : "Imagem";
                // Aqui adicionamos a imagem diretamente no conteúdo, sem tag <a> ao redor
                $content .= " $mediaUrl ";
            }
        }
    }

    // Informações do autor
    if (isset($object["attributedTo"])) {
        $actor = $object["attributedTo"];
    } else if (isset($message["actor"])) {
        $actor = $message["actor"];
    } else {
        $actor = "https://example.com/anonymous";
    }
    $actorArray = explode("/", $actor);
    $actorName = end($actorArray);
    $actorServer = parse_url($actor, PHP_URL_HOST);
    $actorUsername = "@{$actorName}@{$actorServer}";

    // Criar a entrada do item RSS
    echo '<item>';
    echo '<title>' . htmlspecialchars($actorUsername, ENT_XML1, 'UTF-8') . '</title>';
    echo '<link>' . htmlspecialchars($id, ENT_XML1, 'UTF-8') . '</link>';
    echo '<description>' . htmlspecialchars($content, ENT_XML1, 'UTF-8') . '</description>';
    echo '<pubDate>' . $formattedDate . '</pubDate>';
    echo '<guid>' . htmlspecialchars($id, ENT_XML1, 'UTF-8') . '</guid>';
    echo '</item>';
}

// Fechar a tag do canal e do RSS
echo '</channel>';
echo '</rss>';
		die();
	}

//	This creates a UI for feed
function feed() {
// Defina a variável $data com o caminho onde os dados estão armazenados
$data = "data";  // O diretório onde estão os dados (aqui está definido como 'data', mas pode ser alterado conforme necessário)

// Estrutura dos diretórios onde os arquivos .json estão armazenados
$directories = array(
    "posts"      => "posts",              // Diretório posts
);

// Defina os diretórios que você deseja usar (por exemplo, "inbox" e "posts")
$directories_to_use = array("posts");

// Inicialize um array vazio para armazenar os arquivos de ambos os diretórios
$message_files = array();

// Obtenha todos os arquivos .json de cada diretório
foreach ($directories_to_use as $directory) {
    $message_files = array_merge($message_files, glob($directories[$directory] . "/*.json"));
}

// Ordene os arquivos em ordem decrescente de data (mais recentes primeiro)
$message_files = array_reverse($message_files);

// Pegue os 1000 arquivos mais recentes
$message_files = array_slice($message_files, 0, 1000);

// Inicialize o array para as mensagens ordenadas
$messages_ordered = [];
foreach ($message_files as $message_file) {
    // Divida o nome do arquivo para pegar o tipo da mensagem
    $file_parts = explode(".", $message_file);
    $type = $file_parts[1];

    // Ignore mensagens de tipo "Undo" ou "Delete"
    if ("Undo" == $type || "Delete" == $type) {
        continue;
    }

    // Obtenha o conteúdo do arquivo JSON
    $message = json_decode(file_get_contents($message_file), true);

    // Verificar se a mensagem é uma resposta (inReplyTo não é null)
    if (isset($message["object"])) {
        $object = $message["object"];
    } else {
        $object = $message;
    }
    
    // Pular mensagens que são respostas (inReplyTo não é null)
    if (isset($object["inReplyTo"]) && $object["inReplyTo"] !== null) {
        continue;
    }

    // Verificar se o conteúdo está disponível
    if (!isset($object["content"]) || empty($object["content"])) {
        continue;
    }

    // Ordenar mensagens por data de publicação
    if (isset($message["published"])) {
        $published = $message["published"];
    } else {
        // Caso não haja data "published", use o timestamp do nome do arquivo
        $segments = explode("/", explode("-", $message_file ?? "")[0]);
        $published_hexstamp = end($segments);
        $published_time = hexdec($published_hexstamp);
        $published = date("c", $published_time);
    }

    // Coloque as mensagens no array, com chave sendo o timestamp
    $messages_ordered[$published] = $message;
}

// Ordenar as mensagens com as mais recentes primeiro
krsort($messages_ordered);

// Pegue as 200 mensagens mais recentes
$messages_ordered = array_slice($messages_ordered, 0, 50);

// Cabeçalho do RSS
header('Content-type: text/xml');
echo '<rss version="2.0">';
echo '<channel>';
echo '<title>Domingos Faria | Activitypub</title>';
echo '<link>https://social.dfaria.eu/feed.php</link>';
echo '<description>Domingos Faria Social Network</description>';
echo '<language>PT-pt</language>';

// Permitir alguns elementos HTML seguros
$allowed_elements = ["p", "span", "br", "a", "del", "pre", "code", "em", "strong", "b", "i", "u", "ul", "ol", "li", "blockquote"];

// Loop através das mensagens ordenadas e adicionar cada uma ao RSS
foreach ($messages_ordered as $published => $message) {
    // Verificar a mensagem e obter o autor e conteúdo
    if (isset($message["object"])) {
        $object = $message["object"];
    } else {
        $object = $message;
    }

    // Verificar se a mensagem tem um ID
    $id = isset($object["id"]) ? $object["id"] : "";
    $publishedDate = new DateTime($published);
    $formattedDate = $publishedDate->format('D, d M Y H:i:s T');

    // Conteúdo da mensagem
    $content = $object["content"]; // Removido o fallback para "Conteúdo indisponível"
    $content = strip_tags($content, implode(",", $allowed_elements));
    // Transformar URLs em links
    $content = preg_replace_callback('#(https?://[^\s]+)#', function($matches) {
        return ' ' . $matches[0] . ' ';
    }, $content);

    // Verificar se a mensagem tem imagens anexadas
    if (isset($object["attachment"])) {
        foreach ($object["attachment"] as $attachment) {
            // Verifique se é uma imagem e adicione ao conteúdo
            if (isset($attachment["mediaType"]) && strpos($attachment["mediaType"], "image") === 0) {
                $mediaUrl = $attachment["url"];
                $alt = isset($attachment["name"]) ? htmlspecialchars($attachment["name"]) : "Imagem";
                // Aqui adicionamos a imagem diretamente no conteúdo, sem tag <a> ao redor
                $content .= " $mediaUrl ";
            }
        }
    }

    // Informações do autor
    if (isset($object["attributedTo"])) {
        $actor = $object["attributedTo"];
    } else if (isset($message["actor"])) {
        $actor = $message["actor"];
    } else {
        $actor = "https://example.com/anonymous";
    }
    $actorArray = explode("/", $actor);
    $actorName = end($actorArray);
    $actorServer = parse_url($actor, PHP_URL_HOST);
    $actorUsername = "@{$actorName}@{$actorServer}";

    // Criar a entrada do item RSS
    echo '<item>';
    echo '<title>' . htmlspecialchars($actorUsername, ENT_XML1, 'UTF-8') . '</title>';
    echo '<link>' . htmlspecialchars($id, ENT_XML1, 'UTF-8') . '</link>';
    echo '<description>' . htmlspecialchars($content, ENT_XML1, 'UTF-8') . '</description>';
    echo '<pubDate>' . $formattedDate . '</pubDate>';
    echo '<guid>' . htmlspecialchars($id, ENT_XML1, 'UTF-8') . '</guid>';
    echo '</item>';
}

// Fechar a tag do canal e do RSS
echo '</channel>';
echo '</rss>';
	die();
}

//	This creates a UI for twtxt
function twtxt() {
// Defina a variável $data com o caminho onde os dados estão armazenados
$data = "data";  // O diretório onde estão os dados (aqui está definido como 'data', mas pode ser alterado conforme necessário)

// Estrutura dos diretórios onde os arquivos .json estão armazenados
$directories = array(
    "posts" => "posts", // Diretório posts
);

// Defina os diretórios que você deseja usar (por exemplo, "inbox" e "posts")
$directories_to_use = array("posts");

// Inicialize um array vazio para armazenar os arquivos de ambos os diretórios
$message_files = array();

// Obtenha todos os arquivos .json de cada diretório
foreach ($directories_to_use as $directory) {
    $message_files = array_merge($message_files, glob($directories[$directory] . "/*.json"));
}

// Ordene os arquivos em ordem decrescente de data (mais recentes primeiro)
$message_files = array_reverse($message_files);

// Pegue os 1000 arquivos mais recentes
$message_files = array_slice($message_files, 0, 999999);

// Inicialize o array para as mensagens ordenadas
$messages_ordered = [];
foreach ($message_files as $message_file) {
    // Divida o nome do arquivo para pegar o tipo da mensagem
    $file_parts = explode(".", $message_file);
    $type = $file_parts[1];

    // Ignore mensagens de tipo "Undo" ou "Delete"
    if ("Undo" == $type || "Delete" == $type) {
        continue;
    }

    // Obtenha o conteúdo do arquivo JSON
    $message = json_decode(file_get_contents($message_file), true);

    // Verificar se a mensagem é uma resposta (inReplyTo não é null)
    if (isset($message["object"])) {
        $object = $message["object"];
    } else {
        $object = $message;
    }
    
    // Pular mensagens que são respostas (inReplyTo não é null)
    if (isset($object["inReplyTo"]) && $object["inReplyTo"] !== null) {
        continue;
    }

    // Verificar se o conteúdo está disponível
    if (!isset($object["content"]) || empty($object["content"])) {
        continue;
    }

    // Ordenar mensagens por data de publicação
    if (isset($message["published"])) {
        $published = $message["published"];
    } else {
        // Caso não haja data "published", use o timestamp do nome do arquivo
        $segments = explode("/", explode("-", $message_file ?? "")[0]);
        $published_hexstamp = end($segments);
        $published_time = hexdec($published_hexstamp);
        $published = date("c", $published_time);
    }

    // Coloque as mensagens no array, com chave sendo o timestamp
    $messages_ordered[$published] = $message;
}

// Ordenar as mensagens com as mais recentes primeiro
krsort($messages_ordered);

// Pegue as 200 mensagens mais recentes
$messages_ordered = array_slice($messages_ordered, 0, 999999);

// Configurar o cabeçalho para texto simples (Twtxt)
header('Content-type: text/plain');

// Adicionar os metadados no início do arquivo Twtxt
echo "# TWTXT is a decentralized microblogging platform.\n";
echo "#\n";
echo "# === Metadata ===\n";
echo "#\n";
echo "# Domingos Faria\n";
echo "# nick = df\n";
echo "# description = Domingos Faria is Assistant Professor of Philosophy at the Faculty of Arts and Humanities of the University of Porto (FLUP)\n";
echo "# avatar = https://dfaria.eu/resources/dfaria.jpeg\n";
echo "# url = https://social.dfaria.eu/twtxt.txt\n";
echo "# link = Timeline https://social.dfaria.eu\n";
echo "#\n";
echo "# === Content ===\n";
echo "#\n";

// Loop através das mensagens ordenadas e gerar as linhas no formato Twtxt
foreach ($messages_ordered as $published => $message) {
    // Verificar a mensagem e obter o conteúdo
    if (isset($message["object"])) {
        $object = $message["object"];
    } else {
        $object = $message;
    }

    // Verificar se a mensagem tem um ID
    $id = isset($object["id"]) ? $object["id"] : "";
    $publishedDate = new DateTime($published);
    $formattedDate = $publishedDate->format('Y-m-d\TH:i:s\Z');

    // Conteúdo da mensagem
    $content = $object["content"]; // Removido o fallback para "Conteúdo indisponível"
    $content = strip_tags($content);
    // Transformar URLs em texto limpo
    $content = preg_replace('#(https?://[^\s]+)#', '$1', $content);

 // Verificar se a mensagem tem imagens anexadas
 if (isset($object["attachment"])) {
	foreach ($object["attachment"] as $attachment) {
		// Verifique se é uma imagem e adicione ao conteúdo
		if (isset($attachment["mediaType"]) && strpos($attachment["mediaType"], "image") === 0) {
			$mediaUrl = $attachment["url"];
			$alt = isset($attachment["name"]) ? htmlspecialchars($attachment["name"]) : "Imagem";
			// Aqui adicionamos a imagem diretamente no conteúdo, sem tag <a> ao redor
			$content .= " $mediaUrl ";
		}
	}
}

    // Montar a linha no formato Twtxt
    echo $formattedDate . "	" . $content . "\n";
}
		die();
}	

	// User Interface for Writing:
	// This creates a basic HTML form. Type in your message and your password. It then POSTs the data to the `/action/send` endpoint.
	function write() {
		$send = "/action/send";

		if ( isset( $_GET["announce"] ) && filter_var( $_GET["announce"], FILTER_VALIDATE_URL ) ) {
			$announceURl = $_GET["announce"];
		} else {
			$announceURl = "";
		}

		if ( isset( $_GET["like"] ) && filter_var( $_GET["like"], FILTER_VALIDATE_URL ) ) {
			$likeURl = $_GET["like"];
		} else {
			$likeURl = "";
		}

		if ( isset( $_GET["reply"] ) && filter_var( $_GET["reply"], FILTER_VALIDATE_URL ) ) {
			$replyURl = $_GET["reply"];
		} else {
			$replyURl = "";
		}

		if ( isset( $_GET["content"] ) ) {
			$contentURl = urldecode($_GET["content"]);
		} else {
			$contentURl = "";
		}
		global $password, $username, $server, $realName, $summary, $directories;

		// Handle logout request
		if (isset($_GET['logout'])) {
			setcookie('auth', '', time() - 3600, "/"); // Expire the cookie
			echo <<<HTML
		<!DOCTYPE html>
		<html lang="en">
		<head>
			<meta charset="UTF-8">
			<meta name="viewport" content="width=device-width, initial-scale=1.0">
			<title>Logged Out</title>
			<link rel="stylesheet" href="https://social.dfaria.eu/style.css">
		</head>
		<body>
			<main>
				<h1>You have been logged out</h1>
				<p><a href="/notifications">Click here to log in again</a>.</p>
			</main>
		</body>
		</html>
		HTML;
			die();
		}
		
			// Check if the user is already authenticated via a cookie
			if (isset($_COOKIE['auth']) && $_COOKIE['auth'] === hash('sha256', $password)) {
				$authenticated = true;
			}
		
			// Handle login attempt
			if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['password'])) {
				if ($_POST['password'] === $password) {
					// Correct password: set cookie and authenticate
					setcookie('auth', hash('sha256', $password), time() + 86400 * 30, "/"); // 30 days
					$authenticated = true;
				} else {
					// Incorrect password: show error message
					$error = "Incorrect password. Please try again.";
				}
			}
		
			// If not authenticated, show login form
			if (!isset($authenticated)) {
				echo <<<HTML
		<!DOCTYPE html>
		<html lang="en-GB">
		<head>
			<meta charset="UTF-8">
			<meta name="viewport" content="width=device-width, initial-scale=1.0">
			<meta property="og:url" content="https://{$server}">
			<meta property="og:type" content="website">
			<meta property="og:title" content="Write">
			<meta property="og:description" content="{$summary}">
			<meta property="og:image" content="https://{$server}/banner.jpeg">
			<title>Social: Write</title>
			<link rel="stylesheet" href="https://social.dfaria.eu/style.css">
			<link rel="stylesheet" href="https://use.fontawesome.com/releases/v6.0.0/css/all.css">
		</head>
		<body>
			<main class="h-feed">
				<header>
					<div class="about">
					<p><a href="/"><i class="fa-solid fa-house"></i></a> &emsp;&emsp; <a href="/timeline"><i class="fa-regular fa-comment"></i></a> &emsp;&emsp; <a href="read"><i class="fa-regular fa-comments"></i></a> &emsp;&emsp; <a href="mural"><i class="fa-solid fa-bars-staggered"></i></a> &emsp;&emsp; <a href="write"><i class="fa-regular fa-pen-to-square"></i></a> &emsp;&emsp; <a href="users"><i class="fa-regular fa-user"></i></a> &emsp;&emsp; <a href="notifications"><i class="fa-regular fa-bell"></i></a> &emsp;&emsp; <a href="notifications?logout"><i class="fa-solid fa-arrow-right-to-bracket"></i></a></p>
					</div>
			<main>
				<form method="post">
					<br>
					<input type="password" id="password" name="password" required>
					<button type="submit">Login</button>
				</form>
		HTML;
				if (isset($error)) {
					echo "<p style='color: red;'>$error</p>";
				}
				echo <<<HTML
			</main>
		</body>
		</html>
		HTML;
				die();
			}
		
			// Original notifications function content starts here
			$rawUsername = rawurldecode($username);
				
			echo <<<HTML
<!DOCTYPE html>
<html lang="en-GB">
<head>
		<meta charset="UTF-8">
		<meta name="viewport" content="width=device-width, initial-scale=1.0">
		<meta property="og:url" content="https://{$server}">
		<meta property="og:type" content="website">
		<meta property="og:title" content="Write">
		<meta property="og:description" content="{$summary}">
		<meta property="og:image" content="https://{$server}/banner.jpeg">
		<title>Social: Write</title>
		<link rel="stylesheet" href="https://social.dfaria.eu/style.css">
		<link rel="stylesheet" href="https://use.fontawesome.com/releases/v6.0.0/css/all.css">
	</head>
	<body>
		<main class="h-feed">
			<header>
				<div class="about">
				<p><a href="/"><i class="fa-solid fa-house"></i></a> &emsp;&emsp; <a href="/timeline"><i class="fa-regular fa-comment"></i></a> &emsp;&emsp; <a href="read"><i class="fa-regular fa-comments"></i></a> &emsp;&emsp; <a href="mural"><i class="fa-solid fa-bars-staggered"></i></a> &emsp;&emsp; <a href="write"><i class="fa-regular fa-pen-to-square"></i></a> &emsp;&emsp; <a href="users"><i class="fa-regular fa-user"></i></a> &emsp;&emsp; <a href="notifications"><i class="fa-regular fa-bell"></i></a> &emsp;&emsp; <a href="notifications?logout"><i class="fa-solid fa-arrow-right-to-bracket"></i></a></p>
				</div>
			</header>
	<body>
	<fieldset style="display: none;">
    <legend>Password</legend>
    <input type="password" name="password" id="password" value="$password" size="32"><br><br>
</fieldset>

<fieldset>
    <legend>Send a message</legend>
    <form action="{$send}" method="post" enctype="multipart/form-data">
        <input type="hidden" name="type" value="Create">
        <input type="hidden" name="password" value=""> <!-- Campo oculto para senha -->
        <label for="content">Your message:</label><br>
        <textarea id="content" name="content" rows="5" cols="32" required>{$contentURl}</textarea><br>
        <label for="inReplyTo">Reply to URL:</label>
        <input type="url" name="inReplyTo" id="inReplyTo" size="32" value="{$replyURl}"><br>
        <label for="image">Attach an image:</label><br>
        <input type="file" name="image" id="image" accept="image/*"><br>
        <label for="alt">Alt Text:</label>
        <input type="text" name="alt" id="alt" size="32"><br>
        <input type="submit" value="Post Message" onclick="this.form.password.value=document.getElementById('password').value">
    </form>
</fieldset>

<fieldset>
    <legend>Like a post</legend>
    <form action="{$send}" method="post" enctype="multipart/form-data">
        <input type="hidden" name="type" value="Like">
        <input type="hidden" name="password" value=""> <!-- Campo oculto para senha -->
        <label for="postURlLike">URL of post to like:</label>
        <input type="url" name="postURl" id="postURlLike" size="32" value="{$likeURl}"><br>
        <input type="submit" value="Like the message" onclick="this.form.password.value=document.getElementById('password').value">
    </form>
</fieldset>

<fieldset>
    <legend>Boost a post</legend>
    <form action="{$send}" method="post" enctype="multipart/form-data">
        <input type="hidden" name="type" value="Announce">
        <input type="hidden" name="password" value=""> <!-- Campo oculto para senha -->
        <label for="postURlBoost">URL of post to boost:</label>
        <input type="url" name="postURl" id="postURlBoost" size="32" value="{$announceURl}"><br>
        <input type="submit" value="Boost the message" onclick="this.form.password.value=document.getElementById('password').value">
    </form>
</fieldset>
	</body>
</html>
HTML;
		die();
	}	

	//	Send Endpoint:
	//	This takes the submitted message and checks the password is correct.
	//	It reads all the followers' data in `data/followers`.
	//	It constructs a list of shared inboxes and unique inboxes.
	//	It sends the message to every server that is following this account.
	function send() {
		global $password, $server, $username, $key_private, $directories;

		//	Does the posted password match the stored password?
		if( $password != $_POST["password"] ) { echo "Wrong password."; die(); }

		//	What sort of message is being sent?
		$type = $_POST["type"];

		//	Likes and Announces have an identical message structure
		if ( "Like" == $type || "Announce" == $type ) {
			//	Was a URl sent?
			if ( isset( $_POST["postURl"] ) && filter_var( $_POST["postURl"], FILTER_VALIDATE_URL ) ) {
				$postURl = $_POST["postURl"];
			} else {
				echo "No valid URl sent.";
				die();
			}

			if ( "Like" == $type ) {
				//	The message will need to be sent to the inbox of the author of the message
				$inbox_single = getInboxFromMessageURl( $postURl );
			}

			//	Outgoing Message ID
			$guid = uuid();

			//	Construct the Message
			$message = [
				"@context" => "https://www.w3.org/ns/activitystreams",
				"id"       => "https://{$server}/posts/{$guid}.json",
				"type"     => $type,
				"actor"    => "https://{$server}/{$username}",
				"published"=> date( "c" ),
				"object"   => $postURl
			];

			//	Announces are sent to an audience
			//	The audience is public and it is sent to all followers
			//	TODO: Let the original poster know we boosted them
			if ( $type == "Announce" ) {
				$message = array_merge( $message, 
					array(
						"to" => ["https://www.w3.org/ns/activitystreams#Public"],
						"cc" => ["https://{$server}/followers"])
				);
			}

			//	Construct the Note
			//	This is for saving in the logs
			$note = $message;

		} else if ( $type == "Create" ) {
			//	Get the posted content
			$content = $_POST["content"];

			//	Is this a reply?
			if ( isset( $_POST["inReplyTo"] ) && filter_var( $_POST["inReplyTo"], FILTER_VALIDATE_URL ) ) {
				$inReplyTo = $_POST["inReplyTo"];
			} else {
				$inReplyTo = null;
			}

			//	Process the content into HTML to get hashtags etc
			list( "HTML" => $content, "TagArray" => $tags ) = process_content( $content );

			//	Is there an image attached?
			if ( isset( $_FILES['image']['tmp_name'] ) && ( "" != $_FILES['image']['tmp_name'] ) ) {
				//	Get information about the image
				$image      = $_FILES['image']['tmp_name'];
				$image_info = getimagesize( $image );
				$image_ext  = image_type_to_extension( $image_info[2] );
				$image_mime = $image_info["mime"];

				//	Files are stored according to their hash
				//	A hash of "abc123" is stored in "/images/abc123.jpg"
				$sha1 = sha1_file( $image );
				$image_full_path = $directories["images"] . "/{$sha1}.{$image_ext}";

				//	Move media to the correct location
				move_uploaded_file( $image, $image_full_path );

				//	Get the alt text
				if ( isset( $_POST["alt"] ) ) {
					$alt = $_POST["alt"];
				} else {
					$alt = "";
				}

				//	Construct the attachment value for the post
				$attachment = array( [
					"type"      => "Image",
					"mediaType" => "{$image_mime}",
					"url"       => "https://{$server}/{$image_full_path}",
					"name"      => $alt
				] );
			} else {
				$attachment = [];
			}

			//	Current time - ISO8601
			$timestamp = date( "c" );

			//	Outgoing Message ID
			$guid = uuid();

			//	Construct the Note
			//	`contentMap` is used to prevent unnecessary "translate this post" pop ups
			// hardcoded to English
			$note = [
				"@context"     => array(
					"https://www.w3.org/ns/activitystreams"
				),
				"id"           => "https://{$server}/posts/{$guid}.json",
				"type"         => "Note",
				"published"    => $timestamp,
				"attributedTo" => "https://{$server}/{$username}",
				"inReplyTo"    => $inReplyTo,
				"content"      => $content,
				"contentMap"   => ["en" => $content],
				"to"           => ["https://www.w3.org/ns/activitystreams#Public"],
				"tag"          => $tags,
				"attachment"   => $attachment
			];

			//	Construct the Message
			//	The audience is public and it is sent to all followers
			$message = [
				"@context" => "https://www.w3.org/ns/activitystreams",
				"id"       => "https://{$server}/posts/{$guid}.json",
				"type"     => "Create",
				"actor"    => "https://{$server}/{$username}",
				"to"       => [
					"https://www.w3.org/ns/activitystreams#Public"
				],
				"cc"       => [
					"https://{$server}/followers"
				],
				"object"   => $note
			];
		}

		//	Save the permalink
		$note_json = json_encode( $note );
		file_put_contents( $directories["posts"] . "/{$guid}.json", print_r( $note_json, true ) );

		//	Is this message going to one user? (Usually a Like)
		if ( isset( $inbox_single ) ) {
			$messageSent = sendMessageToSingle( $inbox_single, $message );
		} else {	//	Send to all the user's followers
			$messageSent = sendMessageToFollowers( $message );
		}

		//	Render the JSON so the user can see the POST has worked
		if ( $messageSent ) {
			header( "Location: https://{$server}/posts/{$guid}.json" );
			die();	
		} else {
			echo "ERROR!";
			die();
		}
	}

	//Enviar API
	/*
	function enviar() {
		global $password, $server, $username, $key_private, $directories;

		//	Does the posted password match the stored password?
		if( $password != $_GET["password"] ) { echo "Wrong password."; die(); }

		//	What sort of message is being sent?
		$type = $_GET["type"];

		//	Likes and Announces have an identical message structure
		if ( "Like" == $type || "Announce" == $type ) {
			//	Was a URl sent?
			if ( isset( $_GET["postURl"] ) && filter_var( $_GET["postURl"], FILTER_VALIDATE_URL ) ) {
				$postURl = $_GET["postURl"];
			} else {
				echo "No valid URl sent.";
				die();
			}

			if ( "Like" == $type ) {
				//	The message will need to be sent to the inbox of the author of the message
				$inbox_single = getInboxFromMessageURl( $postURl );
			}

			//	Outgoing Message ID
			$guid = uuid();

			//	Construct the Message
			$message = [
				"@context" => "https://www.w3.org/ns/activitystreams",
				"id"       => "https://{$server}/posts/{$guid}.json",
				"type"     => $type,
				"actor"    => "https://{$server}/{$username}",
				"published"=> date( "c" ),
				"object"   => $postURl
			];

			//	Announces are sent to an audience
			//	The audience is public and it is sent to all followers
			//	TODO: Let the original poster know we boosted them
			if ( $type == "Announce" ) {
				$message = array_merge( $message, 
					array(
						"to" => ["https://www.w3.org/ns/activitystreams#Public"],
						"cc" => ["https://{$server}/followers"])
				);
			}

			//	Construct the Note
			//	This is for saving in the logs
			$note = $message;

		} else if ( $type == "Create" ) {
			//	Get the posted content
			$content = $_GET["content"];

			//	Is this a reply?
			if ( isset( $_GET["inReplyTo"] ) && filter_var( $_GET["inReplyTo"], FILTER_VALIDATE_URL ) ) {
				$inReplyTo = $_GET["inReplyTo"];
			} else {
				$inReplyTo = null;
			}

			//	Process the content into HTML to get hashtags etc
			list( "HTML" => $content, "TagArray" => $tags ) = process_content( $content );

			//	Is there an image attached?
			if ( isset( $_FILES['image']['tmp_name'] ) && ( "" != $_FILES['image']['tmp_name'] ) ) {
				//	Get information about the image
				$image      = $_FILES['image']['tmp_name'];
				$image_info = getimagesize( $image );
				$image_ext  = image_type_to_extension( $image_info[2] );
				$image_mime = $image_info["mime"];

				//	Files are stored according to their hash
				//	A hash of "abc123" is stored in "/images/abc123.jpg"
				$sha1 = sha1_file( $image );
				$image_full_path = $directories["images"] . "/{$sha1}.{$image_ext}";

				//	Move media to the correct location
				move_uploaded_file( $image, $image_full_path );

				//	Get the alt text
				if ( isset( $_POST["alt"] ) ) {
					$alt = $_POST["alt"];
				} else {
					$alt = "";
				}

				//	Construct the attachment value for the post
				$attachment = array( [
					"type"      => "Image",
					"mediaType" => "{$image_mime}",
					"url"       => "https://{$server}/{$image_full_path}",
					"name"      => $alt
				] );
			} else {
				$attachment = [];
			}

			//	Current time - ISO8601
			$timestamp = date( "c" );

			//	Outgoing Message ID
			$guid = uuid();

			//	Construct the Note
			//	`contentMap` is used to prevent unnecessary "translate this post" pop ups
			// hardcoded to English
			$note = [
				"@context"     => array(
					"https://www.w3.org/ns/activitystreams"
				),
				"id"           => "https://{$server}/posts/{$guid}.json",
				"type"         => "Note",
				"published"    => $timestamp,
				"attributedTo" => "https://{$server}/{$username}",
				"inReplyTo"    => $inReplyTo,
				"content"      => $content,
				"contentMap"   => ["en" => $content],
				"to"           => ["https://www.w3.org/ns/activitystreams#Public"],
				"tag"          => $tags,
				"attachment"   => $attachment
			];

			//	Construct the Message
			//	The audience is public and it is sent to all followers
			$message = [
				"@context" => "https://www.w3.org/ns/activitystreams",
				"id"       => "https://{$server}/posts/{$guid}.json",
				"type"     => "Create",
				"actor"    => "https://{$server}/{$username}",
				"to"       => [
					"https://www.w3.org/ns/activitystreams#Public"
				],
				"cc"       => [
					"https://{$server}/followers"
				],
				"object"   => $note
			];
		}
		

		//	Save the permalink
		$note_json = json_encode( $note );
		file_put_contents( $directories["posts"] . "/{$guid}.json", print_r( $note_json, true ) );

		//	Is this message going to one user? (Usually a Like)
		if ( isset( $inbox_single ) ) {
			$messageSent = sendMessageToSingle( $inbox_single, $message );
		} else {	//	Send to all the user's followers
			$messageSent = sendMessageToFollowers( $message );
		}

		//	Render the JSON so the user can see the POST has worked
		if ( $messageSent ) {
			header( "Location: https://{$server}/posts/{$guid}.json" );
			die();	
		} else {
			echo "ERROR!";
			die();
		}
	}
	*/
	
	//	POST a signed message to a single inbox
	function sendMessageToSingle( $inbox, $message ) {
		global $directories;

		$inbox_host  = parse_url( $inbox, PHP_URL_HOST );
		$inbox_path  = parse_url( $inbox, PHP_URL_PATH );

		//	Generate the signed headers
		$headers = generate_signed_headers( $message, $inbox_host, $inbox_path, "POST" );

		//	POST the message and header to the requester's inbox
		$ch = curl_init( $inbox );
		curl_setopt( $ch, CURLOPT_RETURNTRANSFER, true );
		curl_setopt( $ch, CURLOPT_CUSTOMREQUEST, "POST" );
		curl_setopt( $ch, CURLOPT_POSTFIELDS,     json_encode( $message ) );
		curl_setopt( $ch, CURLOPT_HTTPHEADER,     $headers );
		curl_setopt( $ch, CURLOPT_USERAGENT,      USERAGENT );
		curl_exec( $ch );

		//	Check for errors
		if( curl_errno( $ch ) ) {
			$timestamp = ( new DateTime() )->format( DATE_RFC3339_EXTENDED );
			$error_message = curl_error( $ch ) . "\ninbox: {$inbox}\nmessage: " . json_encode($message);
			file_put_contents( $directories["logs"] . "/{$timestamp}.Error.txt", $error_message );
			return false;
		}
		curl_close( $ch );
		return true;
	}

	//	POST a signed message to the inboxes of all followers
	function sendMessageToFollowers( $message ) {
		global $directories;
		//	Read existing followers
		$followers = glob( $directories["followers"] . "/*.json" );
		
		//	Get all the inboxes
		$inboxes = [];
		foreach ( $followers as $follower ) {
			//	Get the data about the follower
			$follower_info = json_decode( file_get_contents( $follower ), true );

			//	Some servers have "Shared inboxes"
			//	If you have lots of followers on a single server, you only need to send the message once.
			if( isset( $follower_info["endpoints"]["sharedInbox"] ) ) {
				$sharedInbox = $follower_info["endpoints"]["sharedInbox"];
				if ( !in_array( $sharedInbox, $inboxes ) ) { 
					$inboxes[] = $sharedInbox; 
				}
			} else {
				//	If not, use the individual inbox
				$inbox = $follower_info["inbox"];
				if ( !in_array( $inbox, $inboxes ) ) { 
					$inboxes[] = $inbox; 
				}
			}
		}

		//	Prepare to use the multiple cURL handle
		//	This makes it more efficient to send many simultaneous messages
		$mh = curl_multi_init();

		//	Loop through all the inboxes of the followers
		//	Each server needs its own cURL handle
		//	Each POST to an inbox needs to be signed separately
		foreach ( $inboxes as $inbox ) {
			
			$inbox_host  = parse_url( $inbox, PHP_URL_HOST );
			$inbox_path  = parse_url( $inbox, PHP_URL_PATH );
	
			//	Generate the signed headers
			$headers = generate_signed_headers( $message, $inbox_host, $inbox_path, "POST" );
		
			//	POST the message and header to the requester's inbox
			$ch = curl_init( $inbox );		
			curl_setopt( $ch, CURLOPT_RETURNTRANSFER, true );
			curl_setopt( $ch, CURLOPT_CUSTOMREQUEST, "POST" );
			curl_setopt( $ch, CURLOPT_POSTFIELDS,     json_encode( $message ) );
			curl_setopt( $ch, CURLOPT_HTTPHEADER,     $headers );
			curl_setopt( $ch, CURLOPT_USERAGENT,      USERAGENT );

			//	Add the handle to the multi-handle
			curl_multi_add_handle( $mh, $ch );
		}

		//	Execute the multi-handle
		do {
			$status = curl_multi_exec( $mh, $active );
			if ( $active ) {
				curl_multi_select( $mh );
			}
		} while ( $active && $status == CURLM_OK );

		//	Close the multi-handle
		curl_multi_close( $mh );

		return true;
	}

	//	Content can be plain text. But to add clickable links and hashtags, it needs to be turned into HTML.
	//	Tags are also included separately in the note
	function process_content( $content ) {
		global $server;

		//	Convert any URls into hyperlinks
		$link_pattern = '/\bhttps?:\/\/\S+/iu';	//	Sloppy regex
		$replacement = function ( $match ) {
			$url = htmlspecialchars( $match[0], ENT_QUOTES, "UTF-8" );
			return "<a href=\"$url\">$url</a>";
		};
		$content = preg_replace_callback( $link_pattern, $replacement, $content );	  

		//	Get any hashtags
		$hashtags = [];
		$hashtag_pattern = '/(?:^|\s)\#(\w+)/';	//	Beginning of string, or whitespace, followed by #
		preg_match_all( $hashtag_pattern, $content, $hashtag_matches );
		foreach ( $hashtag_matches[1] as $match ) {
			$hashtags[] = $match;
		}

		//	Construct the tag value for the note object
		$tags = [];
		foreach ( $hashtags as $hashtag ) {
			$tags[] = array(
				"type" => "Hashtag",
				"name" => "#{$hashtag}",
			);
		}

		//	Add HTML links for hashtags into the text
		//	Todo: Make these links do something.
		$content = preg_replace(
			$hashtag_pattern, 
			" <a href='https://{$server}/tag/$1'>#$1</a>", 
			$content
		);

		//	Detect user mentions
		$usernames = [];
		$usernames_pattern = '/@(\S+)@(\S+)/'; //	This is a *very* sloppy regex
		preg_match_all( $usernames_pattern, $content, $usernames_matches );
		foreach ( $usernames_matches[0] as $match ) {
			$usernames[] = $match;
		}

		//	Construct the mentions value for the note object
		//	This goes in the generic "tag" property
		//	TODO: Add this to the CC field & appropriate inbox
		foreach ( $usernames as $username ) {
			list( , $user, $domain ) = explode( "@", $username );
			$tags[] = array(
				"type" => "Mention",
				"href" => "https://{$domain}/@{$user}",
				"name" => "{$username}"
			);

			//	Add HTML links to usernames
			$username_link = "<a href=\"https://{$domain}/@{$user}\">$username</a>";
			$content = str_replace( $username, $username_link, $content );
		}

		// Construct HTML breaks from carriage returns and line breaks
		$linebreak_patterns = array( "\r\n", "\r", "\n" ); // Variations of line breaks found in raw text
		$content = str_replace( $linebreak_patterns, "<br/>", $content );
		
		//	Construct the content
		$content = "<p>{$content}</p>";

		return [
			"HTML"     => $content, 
			"TagArray" => $tags
		];
	}

	//	When given the URl of a post, this looks up the post, finds the user, then returns their inbox or shared inbox
	function getInboxFromMessageURl( $url ) {
		
		//	Get details about the message
		$messageData = getDataFromURl( $url );

		//	The author is the user who the message is attributed to
		if ( isset ( $messageData["attributedTo"] ) && filter_var( $messageData["attributedTo"], FILTER_VALIDATE_URL) ) { 
			$profileData = getDataFromURl( $messageData["attributedTo"] );
		} else {
			return null;
		}
		
		//	Get the shared inbox or personal inbox
		if( isset( $profileData["endpoints"]["sharedInbox"] ) ) {
			$inbox = $profileData["endpoints"]["sharedInbox"];	
		} else {
			//	If not, use the individual inbox
			$inbox = $profileData["inbox"];
		}

		//	Return the destination inbox if it is valid
		if ( filter_var( $inbox, FILTER_VALIDATE_URL) ) {
			return $inbox;
		} else {
			return null;
		}
	}

	//	GET a request to a URl and returns structured data
	function getDataFromURl ( $url ) {
		//	Check this is a valid https address
		if( 
			( filter_var( $url, FILTER_VALIDATE_URL) != true) || 
			(  parse_url( $url, PHP_URL_SCHEME     ) != "https" )
		) { die(); }

		//	Split the URL
		$url_host  = parse_url( $url, PHP_URL_HOST );
		$url_path  = parse_url( $url, PHP_URL_PATH );

		//	Generate signed headers for this request
		$headers  = generate_signed_headers( null, $url_host, $url_path, "GET" );

		// Set cURL options
		$ch = curl_init( $url );
		curl_setopt( $ch, CURLOPT_RETURNTRANSFER, true );
		curl_setopt( $ch, CURLOPT_FOLLOWLOCATION, true );
		curl_setopt( $ch, CURLOPT_HTTPHEADER,     $headers );
		curl_setopt( $ch, CURLOPT_USERAGENT,      USERAGENT );

		// Execute the cURL session
		$urlJSON = curl_exec( $ch );

		$status_code = curl_getinfo( $ch, CURLINFO_RESPONSE_CODE );

		// Check for errors
		if ( curl_errno( $ch ) || $status_code == 404 ) {
			// Handle cURL error
			$timestamp = ( new DateTime() )->format( DATE_RFC3339_EXTENDED );
			$error_message = curl_error( $ch ) . "\nURl: {$url}\nHeaders: " . json_encode( $headers );
			file_put_contents( $directories["logs"] . "/{$timestamp}.Error.txt", $error_message );
			die();
		} 

		// Close cURL session
		curl_close( $ch );

		return json_decode( $urlJSON, true );
	}

	//	The Outbox contains a date-ordered list (newest first) of all the user's posts
	//	This is optional.
	function outbox() {
		global $server, $username, $directories;

		//	Get all posts
		$posts = array_reverse( glob( $directories["posts"] . "/*.json") );
		//	Number of posts
		$totalItems = count( $posts );
		//	Create an ordered list
		$orderedItems = [];
		foreach ( $posts as $post ) {
			$postData = json_decode( file_get_contents( $post ), true );
			$orderedItems[] = array(
				"type"   => $postData["type"],
				"actor"  => "https://{$server}/{$username}",
				"object" => "https://{$server}/{$post}"
			);
		}

		//	Create User's outbox
		$outbox = array(
			"@context"     => "https://www.w3.org/ns/activitystreams",
			"id"           => "https://{$server}/outbox",
			"type"         => "OrderedCollection",
			"totalItems"   =>  $totalItems,
			"summary"      => "All the user's posts",
			"orderedItems" =>  $orderedItems
		);

		//	Render the page
		header( "Content-Type: application/activity+json" );
		echo json_encode( $outbox );
		die();
	}

	//	This creates a UI for the user to follow another user
	function users() {
		if ( isset( $_GET["account"] ) ) {
			$accountURl = htmlspecialchars( $_GET["account"] );
		} else {
			$accountURl = "";
		}
		global $password, $username, $server, $realName, $summary, $directories;
		
			// Check if the user is already authenticated via a cookie
			if (isset($_COOKIE['auth']) && $_COOKIE['auth'] === hash('sha256', $password)) {
				$authenticated = true;
			}
		
			// Handle login attempt
			if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['password'])) {
				if ($_POST['password'] === $password) {
					// Correct password: set cookie and authenticate
					setcookie('auth', hash('sha256', $password), time() + 86400 * 30, "/"); // 30 days
					$authenticated = true;
				} else {
					// Incorrect password: show error message
					$error = "Incorrect password. Please try again.";
				}
			}
		
			// If not authenticated, show login form
			if (!isset($authenticated)) {
				echo <<<HTML
		<!DOCTYPE html>
		<html lang="en-GB">
		<head>
			<meta charset="UTF-8">
			<meta name="viewport" content="width=device-width, initial-scale=1.0">
			<meta property="og:url" content="https://{$server}">
			<meta property="og:type" content="website">
			<meta property="og:title" content="Following">
			<meta property="og:description" content="{$summary}">
			<meta property="og:image" content="https://{$server}/banner.jpeg">
			<title>Social: Following</title>
			<link rel="stylesheet" href="https://social.dfaria.eu/style.css">
			<link rel="stylesheet" href="https://use.fontawesome.com/releases/v6.0.0/css/all.css">
		</head>
		<body>
			<main class="h-feed">
				<header>
					<div class="about">
					<p><a href="/"><i class="fa-solid fa-house"></i></a> &emsp;&emsp; <a href="/timeline"><i class="fa-regular fa-comment"></i></a> &emsp;&emsp; <a href="read"><i class="fa-regular fa-comments"></i></a> &emsp;&emsp; <a href="mural"><i class="fa-solid fa-bars-staggered"></i></a> &emsp;&emsp; <a href="write"><i class="fa-regular fa-pen-to-square"></i></a> &emsp;&emsp; <a href="users"><i class="fa-regular fa-user"></i></a> &emsp;&emsp; <a href="notifications"><i class="fa-regular fa-bell"></i></a> &emsp;&emsp; <a href="notifications?logout"><i class="fa-solid fa-arrow-right-to-bracket"></i></a></p>
					</div>
			<main>
				<form method="post">
					<br>
					<input type="password" id="password" name="password" required>
					<button type="submit">Login</button>
				</form>
		HTML;
				if (isset($error)) {
					echo "<p style='color: red;'>$error</p>";
				}
				echo <<<HTML
			</main>
		</body>
		</html>
		HTML;
				die();
			}
		
			// Original notifications function content starts here
			$rawUsername = rawurldecode($username);
				
			echo <<<HTML
<!DOCTYPE html>
<html lang="en-GB">
<head>
		<meta charset="UTF-8">
		<meta name="viewport" content="width=device-width, initial-scale=1.0">
		<meta property="og:url" content="https://{$server}">
		<meta property="og:type" content="website">
		<meta property="og:title" content="Follow">
		<meta property="og:description" content="{$summary}">
		<meta property="og:image" content="https://{$server}/banner.jpeg">
		<title>Social: Follow</title>
		<link rel="stylesheet" href="https://social.dfaria.eu/style.css">
		<link rel="stylesheet" href="https://use.fontawesome.com/releases/v6.0.0/css/all.css">
	</head>
	<body>
		<main class="h-feed">
			<header>
				<div class="about">
				<p><a href="/"><i class="fa-solid fa-house"></i></a> &emsp;&emsp; <a href="/timeline"><i class="fa-regular fa-comment"></i></a> &emsp;&emsp; <a href="read"><i class="fa-regular fa-comments"></i></a> &emsp;&emsp; <a href="mural"><i class="fa-solid fa-bars-staggered"></i></a> &emsp;&emsp; <a href="write"><i class="fa-regular fa-pen-to-square"></i></a> &emsp;&emsp; <a href="users"><i class="fa-regular fa-user"></i></a> &emsp;&emsp; <a href="notifications"><i class="fa-regular fa-bell"></i></a> &emsp;&emsp; <a href="notifications?logout"><i class="fa-solid fa-arrow-right-to-bracket"></i></a></p>
				</div>
			</header>
	<body>
		<form action="/action/users" method="post" enctype="multipart/form-data">
			<label   for="user">User:</label>
			<input  name="user" id="user" type="text" size="32" placeholder="@user@example.com" value="{$accountURl}"><br>
			<label   for="action">action</label><br>
			<select name="action" id="action">
				<option value="">--Please choose an option--</option>
				<option value="Follow">Follow</option>
				<option value="Unfollow">Unfollow</option>
				<option value="Block">Block</option>
				<option value="Unblock">Unblock</option>
			</select><br>
			<input  name="password" id="password" type="password" value="$password" size="32" style="display: none;"><br>
			<input  type="submit"  value="Send User Request"> 
		</form>
	</body>
</html>
HTML;
		die();
	}

	//	This receives a request to follow an external user
	//	It looks up the external user's details
	//	Then it sends a follow request
	// If the request is accepted, it saves the details in `data/following/` as a JSON file
	function action_users() {
		global $password, $server, $username, $key_private, $directories;

		//	Does the posted password match the stored password?
		if( $password != $_POST["password"] ) { echo "Wrong Password!"; die(); }

		//	Get the posted content
		$user   = $_POST["user"];
		$action = $_POST["action"];

		//	Is this a valid action?
		if ( match( $action ) {
			"Follow", "Unfollow", "Block", "Unblock" => false,
			default => true,
		} ) {
			//	Discard it, no further processing.
			echo "{$action} not supported";
			die();
		}

		//	Split the user (@user@example.com) into username and server
		list( , $follow_name, $follow_server ) = explode( "@", $user );

		//	Get the Webfinger
		//	This request does not always need to be signed, but safest to do so anyway.
		$webfingerURl  = "https://{$follow_server}/.well-known/webfinger?resource=acct:{$follow_name}@{$follow_server}";
		$webfinger = getDataFromURl( $webfingerURl );

		//	Get the link to the user
		foreach( $webfinger["links"] as $link ) {
			if ( "self" == $link["rel"] ) {
				$profileURl = $link["href"];
			}
		}
		if ( !isset( $profileURl ) ) { echo "No profile" . print_r( $webfinger, true ); die(); }
		
		//	Get the user's details
		$profileData = getDataFromURl( $profileURl );

		// Get the user's inbox
		$profileInbox = $profileData["inbox"];

		//	Create a user request
		$guid = uuid();

		//	Different user actions have subtly different messages to send.
		if ( "Follow" == $action ) {
			$message = [
				"@context" => "https://www.w3.org/ns/activitystreams",
				"id"       => "https://{$server}/{$guid}",
				"type"     => "Follow",
				"actor"    => "https://{$server}/{$username}",
				"object"   => $profileURl
			];
		} else if ( "Unfollow" == $action ) {
			$message = [
				"@context" => "https://www.w3.org/ns/activitystreams",
				"id"       => "https://{$server}/{$guid}",
				"type"     => "Undo",
				"actor"    => "https://{$server}/{$username}",
				"object"   => array(
					//"id" => null,	//	Should be the original ID if possible, but not necessary https://www.w3.org/wiki/ActivityPub/Primer/Referring_to_activities
					"type" => "Follow",
					"actor" => "https://{$server}/{$username}",
					"object" => $profileURl
			  )
			];
		} else if ( "Block" == $action ) {
			$message = [
				"@context" => "https://www.w3.org/ns/activitystreams",
				"id"       => "https://{$server}/{$guid}",
				"type"     => "Block",
				"actor"    => "https://{$server}/{$username}",
				"object"   =>  $profileURl,
				"to"       =>  $profileURl
			];
		} else if ( "Unblock" == $action ) {
			$message = [
				"@context" => "https://www.w3.org/ns/activitystreams",
				"id"       => "https://{$server}/{$guid}",
				"type"     => "Undo",
				"actor"    => "https://{$server}/{$username}",
				"object"   => array(
					//"id" => null,	//	Should be the original ID if possible, but not necessary https://www.w3.org/wiki/ActivityPub/Primer/Referring_to_activities
					"type" => "Block",
					"actor" => "https://{$server}/{$username}",
					"object" => $profileURl
			  )
			];
		}

		// Sign & send the request
		sendMessageToSingle( $profileInbox, $message );

		if ( "Follow" == $action ) {
			//	Save the user's details
			$following_filename = urlencode( $profileURl );
			file_put_contents( $directories["following"] . "/{$following_filename}.json", json_encode( $profileData ) );

			//	Render the JSON so the user can see the POST has worked
			header( "Location: https://{$server}/data/following/" . urlencode( $following_filename ) . ".json" );
		} else if ( "Block" == $action || "Unfollow" == $action ) {
			//	Delete the user if they exist in the following directory.
			$following_filename = urlencode( $profileURl );
			unlink( $directories["following"] . "/{$following_filename}.json" );

			//	Let the user know it worked
			echo "{$user} {$action}ed!";
		} else if ( "Unblock" == $action ) {
			//	Let the user know it worked
			echo "{$user} {$action}ed!";
		}
		die();
	} 

	//	Verify the signature sent with the message.
	//	This is optional
	//	It is very confusing
	function verifyHTTPSignature() {
		global $input, $body, $server, $directories;

		//	What type of message is this? What's the time now?
		//	Used in the log filename.
		$type = urlencode( $body["type"] );
		$timestamp = ( new DateTime() )->format( DATE_RFC3339_EXTENDED );

		//	Get the headers send with the request
		$headers = getallheaders();
		//	Ensure the header keys match the format expected by the signature 
		$headers = array_change_key_case( $headers, CASE_LOWER );

		//	Validate the timestamp
		//	7.2.4 of https://datatracker.ietf.org/doc/rfc9421/ 
		if ( !isset( $headers["date"] ) ) { 
			//	No date set
			//	Filename for the log
			$filename  = "{$timestamp}.{$type}.Signature.Date_Failure.txt";

			//	Save headers and request data to the timestamped file in the logs directory
			file_put_contents( $directories["logs"] . "/{$filename}", 
				"Original Body:\n"    . print_r( $body, true )       . "\n\n" .
				"Original Headers:\n" . print_r( $headers, true )    . "\n\n"			
			);
			return null; 
		}
		$dateHeader = $headers["date"];
		$headerDatetime  = DateTime::createFromFormat('D, d M Y H:i:s T', $dateHeader);
		$currentDatetime = new DateTime();

		//	First, check if the message was sent no more than ± 1 hour
		//	https://github.com/mastodon/mastodon/blob/82c2af0356ff888e9665b5b08fda58c7722be637/app/controllers/concerns/signature_verification.rb#L11
		// Calculate the time difference in seconds
		$timeDifference = abs( $currentDatetime->getTimestamp() - $headerDatetime->getTimestamp() );
		if ( $timeDifference > 3600 ) { 
			//	Write a log detailing the error
			//	Filename for the log
			$filename  = "{$timestamp}.{$type}.Signature.Delay_Failure.txt";

			//	Save headers and request data to the timestamped file in the logs directory
			file_put_contents( $directories["logs"] . "/{$filename}", 
				"Header Date:\n"      . print_r( $dateHeader, true ) . "\n" .
				"Server Date:\n"      . print_r( $currentDatetime->format('D, d M Y H:i:s T'), true ) ."\n" .
				"Original Body:\n"    . print_r( $body, true )       . "\n\n" .
				"Original Headers:\n" . print_r( $headers, true )    . "\n\n"
			);
			return false; 
		}

		//	Is there a significant difference between the Date header and the published timestamp?
		//	Two minutes chosen because Friendica is frequently more than a minute skewed
		$published = $body["published"];
		$publishedDatetime = new DateTime($published);
		// Calculate the time difference in seconds
		$timeDifference = abs( $publishedDatetime->getTimestamp() - $headerDatetime->getTimestamp() );
		if ( $timeDifference > 120 ) { 
			//	Write a log detailing the error
			//	Filename for the log
			$filename  = "{$timestamp}.{$type}.Signature.Time_Failure.txt";

			//	Save headers and request data to the timestamped file in the logs directory
			file_put_contents( $directories["logs"] . "/{$filename}", 
				"Header Date:\n"      . print_r( $dateHeader, true ) . "\n" .
				"Published Date:\n"   . print_r( $publishedDatetime->format('D, d M Y H:i:s T'), true ) ."\n" .
				"Original Body:\n"    . print_r( $body, true )       . "\n\n" .
				"Original Headers:\n" . print_r( $headers, true )    . "\n\n"
			);
			return false; 
		}

		//	Validate the Digest
		//	It is the hash of the raw input string, in binary, encoded as base64.
		$digestString = $headers["digest"];

		//	Usually in the form `SHA-256=Ofv56Jm9rlowLR9zTkfeMGLUG1JYQZj0up3aRPZgT0c=`
		//	The Base64 encoding may have multiple `=` at the end. So split this at the first `=`
		$digestData = explode( "=", $digestString, 2 );
		$digestAlgorithm = $digestData[0];
		$digestHash = $digestData[1];

		//	There might be many different hashing algorithms
		//	TODO: Find a way to transform these automatically
		//	See https://github.com/superseriousbusiness/gotosocial/issues/1186#issuecomment-1976166659 and https://github.com/snarfed/bridgy-fed/issues/430 for hs2019
		if ( "SHA-256" == $digestAlgorithm || "hs2019" == $digestAlgorithm ) {
			$digestAlgorithm = "sha256";
		} else if ( "SHA-512" == $digestAlgorithm ) {
			$digestAlgorithm = "sha512";
		}

		//	Manually calculate the digest based on the data sent
		$digestCalculated = base64_encode( hash( $digestAlgorithm, $input, true ) );

		//	Does our calculation match what was sent?
		if ( !( $digestCalculated == $digestHash ) ) { 
			//	Write a log detailing the error
			$filename  = "{$timestamp}.{$type}.Signature.Digest_Failure.txt";

			//	Save headers and request data to the timestamped file in the logs directory
			file_put_contents( $directories["logs"] . "/{$filename}", 
				"Original Input:\n"    . print_r( $input, true )    . "\n" .
				"Original Digest:\n"   . print_r( $digestString, true ) . "\n" .
				"Calculated Digest:\n" . print_r( $digestCalculated, true ) . "\n"
			);
			return false; 
		}

		//	Examine the signature
		$signatureHeader = $headers["signature"];

		// Extract key information from the Signature header
		$signatureParts = [];
		//	Converts 'a=b,c=d e f' into ["a"=>"b", "c"=>"d e f"]
		               // word="text"
		preg_match_all('/(\w+)="([^"]+)"/', $signatureHeader, $matches);
		foreach ( $matches[1] as $index => $key ) {
			$signatureParts[$key] = $matches[2][$index];
		}

		//	Manually reconstruct the header string
		$signatureHeaders = explode(" ", $signatureParts["headers"] );
		$signatureString = "";
		foreach ( $signatureHeaders as $signatureHeader ) {
			if ( "(request-target)" == $signatureHeader ) {
				$method = strtolower( $_SERVER["REQUEST_METHOD"] );
				$target =             $_SERVER["REQUEST_URI"];
				$signatureString .= "(request-target): {$method} {$target}\n";
			} else if ( "host" == $signatureHeader ) {
				$host = strtolower( $_SERVER["HTTP_HOST"] );	
				$signatureString .= "host: {$host}\n";
			} else {
				$signatureString .= "{$signatureHeader}: " . $headers[$signatureHeader] . "\n";
			}
		}

		//	Remove trailing newline
		$signatureString = trim( $signatureString );

		//	Get the Public Key
		//	The link to the key might be sent with the body, but is always sent in the Signature header.
		$publicKeyURL = $signatureParts["keyId"];

		//	This is usually in the form `https://example.com/user/username#main-key`
		//	This is to differentiate if the user has multiple keys
		//	TODO: Check the actual key
		$userData  = getDataFromURl( $publicKeyURL );
		$publicKey = $userData["publicKey"]["publicKeyPem"];

		//	Check that the actor's key is the same as the key used to sign the message
		//	Get the actor's public key
		$actorData = getDataFromURl( $body["actor"] );
		$actorPublicKey = $actorData["publicKey"]["publicKeyPem"];

		if ( $publicKey != $actorPublicKey ) {	
			//	Filename for the log
			$filename  = "{$timestamp}.{$type}.Signature.Mismatch_Failure.txt";

			//	Save headers and request data to the timestamped file in the logs directory
			file_put_contents( $directories["logs"] . "/{$filename}", 
				"Original Body:\n"              . print_r( $body, true )             . "\n\n" .
				"Original Headers:\n"           . print_r( $headers, true )          . "\n\n" .
				"Signature Headers:\n"          . print_r( $signatureHeaders, true ) . "\n\n" .
				"publicKeyURL:\n"               . print_r( $publicKeyURL, true )     . "\n\n" .
				"publicKey:\n"                  . print_r( $publicKey, true )        . "\n\n" .
				"actorPublicKey:\n"             . print_r( $actorPublicKey, true )   . "\n"
			);
			return false;
		}
		
		//	Get the remaining parts
		$signature = base64_decode( $signatureParts["signature"] );
		$algorithm = $signatureParts["algorithm"];

		//	There might be many different signing algorithms
		//	TODO: Find a way to transform these automatically
		//	See https://github.com/superseriousbusiness/gotosocial/issues/1186#issuecomment-1976166659 and https://github.com/snarfed/bridgy-fed/issues/430 for hs2019
		if ( "hs2019" == $algorithm ) {
			$algorithm = "sha256";
		}

		//	Finally! Calculate whether the signature is valid
		//	Returns 1 if verified, 0 if not, false or -1 if an error occurred
		$verified = openssl_verify(
			$signatureString, 
			$signature, 
			$publicKey, 
			$algorithm
		);

		//	Convert to boolean
		if ( $verified === 1 ) {
			$verified = true;
		} elseif ( $verified === 0 ) {
			$verified = false;
		} else {
			$verified = null;
		}
	
		//	Filename for the log
		$filename  = "{$timestamp}.{$type}.Signature.". json_encode( $verified ) . ".txt";

		//	Save headers and request data to the timestamped file in the logs directory
		file_put_contents( $directories["logs"] . "/{$filename}", 
			"Original Body:\n"              . print_r( $body, true )             . "\n\n" .
			"Original Headers:\n"           . print_r( $headers, true )          . "\n\n" .
			"Signature Headers:\n"          . print_r( $signatureHeaders, true ) . "\n\n" .
			"Calculated signatureString:\n" . print_r( $signatureString, true )  . "\n\n" .
			"Calculated algorithm:\n"       . print_r( $algorithm, true )        . "\n\n" .
			"publicKeyURL:\n"               . print_r( $publicKeyURL, true )     . "\n\n" .
			"publicKey:\n"                  . print_r( $publicKey, true )        . "\n\n" .
			"actorPublicKey:\n"             . print_r( $actorPublicKey, true )   . "\n"
		);

		return $verified;
	}

	//	The NodeInfo Protocol is used to identify servers.
	//	It is looked up with `example.com/.well-known/nodeinfo`
	//	See https://nodeinfo.diaspora.software/
	function wk_nodeinfo() {
		global $server;

		$nodeinfo = array(
			"links" => array(
				array(
					 "rel" => "self",
					"type" => "http://nodeinfo.diaspora.software/ns/schema/2.1",
					"href" => "https://{$server}/nodeinfo/2.1"
				)
			)
		);
		header( "Content-Type: application/json" );
		echo json_encode( $nodeinfo );
		die();
	}

	//	The NodeInfo Protocol is used to identify servers.
	//	It is looked up with `example.com/.well-known/nodeinfo` which points to this resource
	//	See http://nodeinfo.diaspora.software/docson/index.html#/ns/schema/2.0#$$expand
	function nodeinfo() {
		global $server, $directories;

		//	Get all posts
		$posts =  glob( $directories["posts"] . "/*.json") ;
		//	Number of posts
		$totalItems = count( $posts );

		$nodeinfo = array(
			"version" => "2.1",	//	Version of the schema, not the software
			"software" => array(
				"name"       => "Single File ActivityPub Server in PHP",
				"version"    => "0.000000001",
				"repository" => "https://gitlab.com/edent/activitypub-single-php-file/"
			),
			"protocols" => array( "activitypub"),
			"services" => array(
				"inbound"  => array(),
				"outbound" => array()
			),
			"openRegistrations" => false,
			"usage" => array(
				"users" => array(
					"total" => 1
				),
				"localPosts" => $totalItems
			),
			"metadata"=> array(
				"nodeName" => "activitypub-single-php-file",
				"nodeDescription" => "This is a single PHP file which acts as an extremely basic ActivityPub server.",
				"spdx" => "AGPL-3.0-or-later"
			)
		);
		header( "Content-Type: application/json" );
		echo json_encode( $nodeinfo );
		die();
	}

	//	Perform the Undo action requested
	function undo( $message ) { 
		global $server, $directories;

		//	Get some basic data
		$type = $message["type"];
		$id   = $message["id"];
		//	The thing being undone
		$object      = $message["object"];

		//	Does the thing being undone have its own ID or Type?
		if ( isset( $object["id"] ) ) {
			$object_id   = $object["id"];
		} else {
			$object_id = $id;
		}

		if ( isset( $object["type"] ) ) {
			$object_type   = $object["type"];
		} else {
			$object_type = $type;
		}

		//	Inbox items are stored as the hash of the original ID
		$object_id_hash = hash( "sha256", $object_id );
		
		//	Find all the inbox messages which have that ID
		$inbox_files = glob( $directories["inbox"] . "/*.json" );
		foreach ( $inbox_files as $inbox_file ) {
			//	Filenames are `data/inbox/[date]-[SHA256 hash0].[Type].json
			// Find the position of the first hyphen and the first dot
			$hyphenPosition = strpos( $inbox_file, '-' );
			$dotPosition    = strpos( $inbox_file, '.' );
			
			if ( $hyphenPosition !== false && $dotPosition !== false ) {
				// Extract the text between the hyphen and the first dot
				$file_id_hash = substr( $inbox_file, $hyphenPosition + 1, $dotPosition - $hyphenPosition - 1);
			} else {
				//	Ignore the file and move to the next.
				continue;
			}

			//	If this has the same hash as the item being undone
			if ( $object_id_hash == $file_id_hash ) {
				//	Delete the file
				unlink( $inbox_file );

				//	If this was the undoing of a follow request, remove the external user from followers 😢
				if ( "Follow" == $object_type ) {
					$actor = $object["actor"];
					$follower_filename = urlencode( $actor );
					unlink( $directories["followers"] . "/{$follower_filename}.json" );
				}
				//	Stop looping
				break;
			}
		}
	}

//	"One to stun, two to kill, three to make sure"
die();
die();
die();