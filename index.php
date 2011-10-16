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
<html lang="en">
<head>
<meta charset=utf-8 />
<title>Twivatar - Twitter Avatar API</title>
<style>
body { 
    font: normal 16px/20px Helvetica, sans-serif;
    background: rgb(237, 237, 236);
    margin: 0;
    margin-top: 40px;
    padding: 0;
}

section, header, footer {
    display: block;
}


#wrapper {
    width: 600px;
    margin: 0 auto;
    background: #fff url(images/shade.jpg) repeat-x center bottom;
    -moz-border-radius: 10px;
    -webkit-border-radius: 10px;
    border-top: 1px solid #fff;
    padding-bottom: 76px;
}

h1 {
    padding-top: 10px;
}

h2 {
    font-size: 100%;
    font-style: italic;
}

header,
article > p,
article > h3,
article > code,
footer a {
    margin: 20px;
}

footer a {
    margin: 20px;
    color: #999;
}

footer a:hover:after {
    content: '...quickly';
}

</style>
<!--[if IE]>
<script src="http://html5shiv.googlecode.com/svn/trunk/html5.js"></script>
<![endif]-->
</head>
<body>
<section id="wrapper">
    <header>
        <h1>Twivatar</h1>
        <h2>Twitter Avatar API</h2>            
    </header>
    <article>
        <p>Twivatar is a <a href="http://en.wikipedia.org/wiki/REST" title="Rest - Wikipedia, the free encyclopedia">RESTful</a> API to a Twitter user's avatar built out of frustration of external Twitter apps breaking when the avatar url is stored, and then changed by that user later on Twitter - the result is a broken image on that app unless they constantly check for profile changes.</p>

        <h3>Usage</h3>
        <code>&lt;img src="http://twivatar.org/[<em>screen_name</em>]" /&gt;</code>
        
        <p>Alternatively you can specify the size image you want from:</p>
        <ul>
            <li>mini (24x24)</li>
            <li>normal (48x48 - default)</li>
            <li>bigger (73x73)</li>
            <li>original</li>
        </ul>
        <code>&lt;img src="http://twivatar.org/[<em>screen_name</em>]/[<em>size</em>]" /&gt;</code>

        <h3>Behind the scenes</h3>
        <p>This is a simple one script app that stores the url of the avatar. When the avatar is requested for <em>x</em> user, it runs the following logic:</p>
        <ol>
            <li>Get the stored avatar url</li>
            <li>If there's no record, go to Twitter and pull the profile_image_url</li>
            <li>If a record is found, perform a <a href="http://en.wikipedia.org/wiki/HTTP%23Request_methods" title="Wikipedia Entry: HTTP#Request methods">HEAD</a> request to test the avatar url</li>
            <li>Finally use a <code>location</code> redirect to the avatar url</li>
        </ol>
        <p>All the code is available on <a href="http://github.com/remy/twivatar/">GitHub</a>, so feel free to fork and contribute.</p>
        <h3>Todo</h3>
        <p>I'd like to upgrade the entire app to read the ETags from S3 (or some kind of cache control), and send them back to the client, so that the browser uses it's local cache if the avatar is available and up to date.</p>
    </article>
    <footer><a href="http://twitter.com/rem">@rem built this</a></footer>
</section>
<script>
var gaJsHost = (("https:" == document.location.protocol) ? "https://ssl." : "http://www.");
document.write(unescape("%3Cscript src='" + gaJsHost + "google-analytics.com/ga.js' type='text/javascript'%3E%3C/script%3E"));
</script>
<script>
try {
var pageTracker = _gat._getTracker("UA-1656750-17");
pageTracker._trackPageview();
} catch(err) {}</script>
</body>
</html>
