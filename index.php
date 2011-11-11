<?php

function grab_url($url) {
    
	$ch = curl_init();
	curl_setopt($ch, CURLOPT_URL, $url);
	curl_setopt($ch, CURLOPT_HEADER, 0);
	curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
	$output = curl_exec($ch);
	curl_close($ch);
	return $output;
}

function grab_and_store($user, $db) {

    $user_profile = json_decode(grab_url('http://api.twitter.com/1/users/show.json?screen_name=' . $user));
    
    if (!$user_profile) {
        return "http://a0.twimg.com/sticky/default_profile_images/default_profile_1_bigger.png";
    } else {
        $image_url = $user_profile->profile_image_url;
        
        if ($db) {
            $sql = sprintf('replace into twitter_avatar (user, url) values ("%s", "%s")', mysql_real_escape_string($user), $image_url);
            mysql_query($sql, $db);          
        }
        
        return $image_url;
    }
}

function head($image_url) {
    $c = curl_init();
    
    // TODO should we include a connection & read timeout here too?
    curl_setopt( $c, CURLOPT_RETURNTRANSFER, true );
    curl_setopt( $c, CURLOPT_CUSTOMREQUEST, 'HEAD' );
    curl_setopt( $c, CURLOPT_HEADER, 1 );
    curl_setopt( $c, CURLOPT_NOBODY, true );
    curl_setopt( $c, CURLOPT_URL, $image_url );

    $res = curl_exec( $c );
    
    if (preg_match('@HTTP/1.1 404 Not Found@', $res)) {
        return false;
    } else {
        return true;
    }
}

function size_image($image_url, $size) {
    if ($size == 'original') {
        $image_url = preg_replace('/_normal\./', '.', $image_url);
    } else if ($size != 'normal') {
        $image_url = preg_replace('/_normal\./', '_' . $size . '.', $image_url);
    }
    
    return $image_url;
}

function redirect($image_url, $size, $db) {
    $image_url = size_image($image_url, $size);
    if ($db) {
        mysql_close($db);
    }
    header('location: ' . $image_url);
}

$user = strtolower(@$_GET['user']);
$size = strtolower(isset($_GET['size']) && in_array(strtolower($_GET['size']), array('mini', 'bigger', 'normal', 'original')) ? $_GET['size'] : 'normal');
$db = null;
$result = null;
// skipping DB to save some performance from my own box, if you host this yourself, set to true
$use_db = false;

if ($user) {
    // use in case of emergencies: skips twitter entirly
    if (false) {
        redirect("http://a0.twimg.com/sticky/default_profile_images/default_profile_1_bigger.png", $size, false);
        exit;
    }

    // connect to DB
    if ($use_db) {
        $db = mysql_connect('localhost', 'root');      
        mysql_select_db('twivatar', $db);
        $result = mysql_query(sprintf('select url from twitter_avatar where user="%s"', mysql_real_escape_string($user)), $db);
    }

    if (!$result || mysql_num_rows($result) == 0) {
        // grab and store - then redirect
        $image_url = grab_and_store($user, $db);
        redirect($image_url, $size, $db);
    } else if (mysql_num_rows($result) > 0) {
        // test if URL is available - then redirect
        $row = mysql_fetch_object($result);
        
        // if the url returned is one of Twitter's O_o static ones, then do a grab
        if (!preg_match('/static\.twitter\.com/', $row->url) && head($row->url)) {
            redirect($row->url, $size, $db);
        } else { // else grab and store - then redirect
            $image_url = grab_and_store($user, $db);
            redirect($image_url, $size, $db);
        }
    }
    
    exit;
}

?>
<!DOCTYPE html>
<html>
  <head>
    <title>Twivatar - Twitter Avatar API</title>
	<meta charset="utf-8">
	<link rel="stylesheet" href="http://twitter.github.com/bootstrap/1.4.0/bootstrap.min.css">
    <style>
		body {
		  padding-top: 50px;
		}

		.container {
		  width: 600px;
		}

		.content {
		  background-color: #fff;
		  padding: 20px;
		  margin: 0 -20px; 
		  -webkit-border-radius: 0 0 6px 6px;
		     -moz-border-radius: 0 0 6px 6px;
		          border-radius: 0 0 6px 6px;
		  -webkit-box-shadow: 0 1px 2px rgba(0,0,0,.15);
		     -moz-box-shadow: 0 1px 2px rgba(0,0,0,.15);
		          box-shadow: 0 1px 2px rgba(0,0,0,.15);
		}
		.modal {
		  position: absolute;
		}
	</style>
  </head>
  
  <body>
	
	<a href="http://github.com/batuhanicoz/twivatar"><img style="position: absolute; top: 30px; right: 0; border: 0;" src="https://a248.e.akamai.net/assets.github.com/img/7afbc8b248c68eb468279e8c17986ad46549fb71/687474703a2f2f73332e616d617a6f6e6177732e636f6d2f6769746875622f726962626f6e732f666f726b6d655f72696768745f6461726b626c75655f3132313632312e706e67" alt="Fork me on GitHub"></a>
		
	<div class="topbar">
      <div class="fill">
        <div class="container">
		  <h3><a href="http://twivatar.herokuapp.com">Twivatar</a></h3>
        </div>
      </div>
    </div>

	<div class="container">
		
		<section id="content">

		  <div class="row">
		  	<div class="span10">
				<div class="page-header">
		        	<h1>Twitter Avatar API</h1>
				</div>
				
					<h4>What is it?</h4>

					<p>Twivatar is a RESTful API to a Twitter user's avatar built out of frustration of external Twitter apps breaking when the avatar url is stored, and then changed by that user later on Twitter - the result is a broken image on that app unless they constantly check for profile changes.</p>
					<p>All the code is available on <a href="https://github.com/batuhanicoz/twivatar/">GitHub</a>, so feel free to fork and contribute.</p>

					<h4>Usage</h4>
					<p><code>&lt;img src="http://twivatar.herokuapp.com/[<em>screen_name</em>]" /&gt;</code></p>

					<p>Alternatively you can specify the size image you want from:</p>
					<ul>
						<li>mini (24x24)</li>
			            <li>normal (48x48 - default)</li>
			            <li>bigger (73x73)</li>
			            <li>original</li>
			        </ul>

					<p><code>&lt;img src="http://twivatar.herokuapp.com/[<em>screen_name</em>]/[<em>size</em>]" /&gt;</code></p>
					<p>Also, if you need it, you can use HTTPS: </p>
					<p><code>&lt;img src="http<strong>s</strong>://twivatar.herokuapp.com/[<em>screen_name</em>]" /&gt;</code>
					(or <code>&lt;img src="http<strong>s</strong>://twivatar.herokuapp.com/[<em>screen_name</em>]/[<em>size</em>]" /&gt;</code>)
					</p>

				</div>
		</div>
		
		
		</section>

		
	    <footer class="footer">
	      <div class="container">
	        <p class="pull-right">
				<a href="https://github.com/batuhanicoz/twivatar">Source code on Github</a></p>
	        <p>
				Twivatar is a fork of <a href="https://twitter.com/rem">@rem</a>'s twivatar.org (<a href="https://github.com/remy/twivatar">on github</a>) by <a href="https://twitter.com/batuhanicoz">@batuhanicoz</a>.
	        </p>
	      </div>
	    </footer>
		
    </div>
	
  	<script src="http://ajax.googleapis.com/ajax/libs/jquery/1.5.2/jquery.min.js" type="text/javascript"></script>
	
	<script src="js/google-code-prettify/prettify.js"></script>
	<script>$(function () { prettyPrint() })</script>
	
	<script type="text/javascript">

	  var _gaq = _gaq || [];
	  _gaq.push(['_setAccount', 'UA-11142497-11']);
	  _gaq.push(['_trackPageview']);

	  (function() {
	    var ga = document.createElement('script'); ga.type = 'text/javascript'; ga.async = true;
	    ga.src = ('https:' == document.location.protocol ? 'https://ssl' : 'http://www') + '.google-analytics.com/ga.js';
	    var s = document.getElementsByTagName('script')[0]; s.parentNode.insertBefore(ga, s);
	  })();

	</script>
  </body>
</html>