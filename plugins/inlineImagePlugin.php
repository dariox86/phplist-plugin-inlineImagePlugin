<?php

/**
 * inlineImage Plugin v2.0a1
 * 
 * This plugin defines a placeholder that allows inline images to be inserted into
 * messages
 *
 * For more information about how to use this plugin, see
 * http://resources.phplist.com/plugins/inlineImage .
 * 
 */

/**
 * Registers the plugin with phplist
 * 
 * @category  phplist
 * @package   inlineImagePlugin
 */

class inlineImagePlugin extends phplistPlugin
{
    /*
     *  Inherited variables
     */
    public $name = 'Inline Image Plugin';
    public $version = '2.0a1';
    public $enabled = false;
    public $authors = 'Arnold Lesikar';
    public $description = 'Allows the use of inline images in messages';
    public $DBstruct =array (	//For creation of the required tables by Phplist
    		'image' => array(
    			"imgid" => array("integer not null primary key auto_increment","Image numerical ID"),
    			"file_name" => array("varchar(255) not null","File name including extension"),
    			"cksum" => array ("varchar(45) not null", "sha1 checksum for the file contents"),
				'local_name' => array("varchar(255) not null","A unique local file name including extension"),
				"type" => array("char(50)", "MIME type of the image"),
				"cid" => array("char(32) not null","MIME content ID")
			),
			'msg' => array(
				"id" => array("integer not null", "Message ID"),
				"imgid" => array("integer not null","Image numerical ID"),
				"imagetag" => array("Text", "HTML image tag with attributes and cid")
			)
		);  				// Structure of database tables for this plugin
	public $tables = array ();	// Table names are prefixed by Phplist
	public $numberPerList = 10;		// Number of images to be listed at once in table
	public $settings = array(
    		"ImageAttachLimit" => array (
      			'value' => 100,
      			'description' => "Limit for size of attached inline images in kB",
      			'type' => 'integer',
      			'allowempty' => 0,
      			"max" => 499,
      			"min" => 10,
      			'category'=> 'campaign',
   			 	)
   			 );
	private $processing_queue = false;
    private $cache;			// Keep inline image info while the queue is being processed
    private $curid;			// ID of the current message being processed
    private $limit;
    private $myname = 'inlineImagePlugin';
    
    public $image_types = array(	// Taken from class.phplistmailer.php
                  'gif'  => 'image/gif',
                  'jpg'  => 'image/jpeg',
                  'jpeg'  => 'image/jpeg',
                  'jpe'  => 'image/jpeg',
                  'bmp'  => 'image/bmp',
                  'png'  => 'image/png',
                  'tif'  => 'image/tiff',
                  'tiff'  => 'image/tiff'
            );
    
    /* No longer have pages associated with this plugin! */
    function adminmenu() {
    	return array ();
  	}
  	  	
  	function __construct()
    {
     	$this->processing_queue = false;    	
		$this->coderoot = dirname(__FILE__) . '/inlineImagePlugin/';
		
		if (!is_dir($this->coderoot))
			mkdir ($this->coderoot);
		$imagedir = $this->coderoot . "images/";
		if (!is_dir($imagedir))
			mkdir ($imagedir);
		
		if (file_exists($this->coderoot . 'ldaimages.php')) {	// Do we have the pages for our version 1
			// Remove old pages
			unlink($this->coderoot . 'edit.php');
			unlink($this->coderoot . 'ldaimages.php');
			
			// Remove old image files as well
			$files = glob($this->coderoot . 'images/*.*');
			foreach ($files as $f)
				unlink ($f);
		} 
		    		
		parent::__construct();
    }
    
    function initialise() {
    	/* Make sure database is up to date */
		global $table_prefix;
		$imgtbl = $this->tables['image'];
		$msgtbl = $this->tables['msg'];

		if (Sql_Table_exists($imgtbl) && (!Sql_Table_Column_Exists($imgtbl, "cksum"))) {	// Have old database tables?
			// Drop the old tables
			Sql_Drop_Table($imgtbl);
			Sql_Drop_Table($msgtbl);
			
			// Flag the plugin as not intialized so that the parent will create the new tables
			$entry = md5('plugin-inlineImagePlugin-initialised');
			$query = sprintf("delete from %s where item='%s'", $GLOBALS["tables"]["config"], $entry); 
			Sql_Query ($query);
			// Force reloading of config arrays, so that our parent sees the plugin
			// as not initialized.
			unset($_SESSION['config']);
  			unset($GLOBALS['config']); 
  			unset($_SESSION["dbtables"]); 	// Empty the cache that still contains our table names
		} 
		
		parent::initialise();

    }
    
    // The value of an attribute in an inline image placeholder
    // $str is the argument searched for the attribute
    // $att is the name of the attribute whose we are seeking
    // If $valueOnly is true, the value of the attribute is returned;
    // otherwise the entire attribute string is returned.
    private function getAttribute($str, $att, $valueOnly = 1) {
		$pat = '/' . $att . '\s*=\s*\S/i';
		preg_match ($pat, $str, $match);
		$char = substr($match[0],strlen($match[0])-1);
		switch ($char) {
			case "'":
				$pat = '/' . $att . "\s*=\s*'([^']*)'/i";
				break;
			case '"':
				$pat = '/' . $att . '\s*=\s*"([^"]*)"/i';
				break;
			default:
				$pat = '/' . $att . '\s*=\s*(\S*)/i';
		}
		preg_match ($pat, $str, $match);
		if ($valueOnly)
			return trim($match[1]);
		else
			return $match[0];
	}
	
	// getMimeType -- Check the extension from the original name of the file
	// then check the file contents for type, using the temp file where we have
	// stored the data. We would not need to do this by a way of a temp file
	// if we could be sure we were running PHP >= 5.3.
	private function getMimeType($ext, $tempfile) {
		/* Right file extension? */
		$is_image = key_exists($ext, $this->image_types);
		if (!$is_image)
			return false;
		$type = $this->image_types[$ext];

		/* Even if it's the right extension, it still might not be a genuine image */				
		if (class_exists('finfo')) {
			$info = new finfo(FILEINFO_MIME);
			$tempary = explode(';', $info->file($tempfile));
			$type = $tempary[0];
			$is_image = in_array($type,  $this->image_types);
			if (!$is_image)
				return false;
		} elseif (function_exists('mime_content_type')) {
			$type = mime_content_type ($tempfile);		// Have no comparable function for a string
			$is_image = in_array($type, $this->image_types);
			if (!$is_image)
				return false;
		}
		return $type;
	}

	/* allowMessageToBeQueued
	* called to verify that the message can be added to the queue
	* @param array messagedata - associative array with all data for campaign
	* @return empty string if allowed, or error string containing reason for not allowing
	*/
	function allowMessageToBeQueued($messagedata = array()) 
	{
		$msg = $messagedata['message'];
		$tempfile = $this->coderoot . 'images/tempimg.tmp';
		$limit = getConfig("ImageAttachLimit");
		
		// Merge the message and template to check the images
		if ($messagedata["template"]) {
    		$req = Sql_Fetch_Row_Query("select template from {$GLOBALS["tables"]["template"]} where id = {$messagedata["template"]}");
    		$template = stripslashes($req[0]);
    		$msg = str_replace("[CONTENT]",$msg, $template);
    	}
				
		/* Class="inline" marks inline images */
		if (preg_match_all('/<img[^<>]+\Winline(?:\W|[^<>])*>/Ui', $msg, $match)) { //Collect inline tags
			$total = 0;
			foreach ($match[0] as $val) {
				$src = $this->getAttribute($val, "src");
				if (!$str = file_get_contents($src))
					return 'Cannot access image file: ' . $val;
				file_put_contents($tempfile, $str);	// Create a temporary file in order to check mime type
													// We do this only because PHP may be earlier than 5.3
				
				/* Is it an image file? */
				if (!$type = $this->getMimeType(pathInfo($src, PATHINFO_EXTENSION), $tempfile))
					return "The URL does not reference an image file in $val";
				$total += strlen($str);
			}

			unlink ($tempfile); 	// Cleanliness is next to godliness!
			/* Total size of the inline files must remain within limits! */
			if ($total > 1000 * $limit)
    			return "Total size of inline images greater than the " . $limit . " kB limit";
		} else  // No inline image files
			return '';
    }
    
    private function getTempFilename($filename) {
    	$fparts = pathinfo($filename);
    	$tempnm = tempnam($this->coderoot . 'images/', $fparts['filename']);
		unlink($tempnm);  // We just want the name, not the file, since we're modifying the name
		$tempnm .= '.' . $fparts['extension'];
		return $tempnm;
	}
    	
	/* messageQueued
	* called when a message is placed in the queue
	* @param integer id message id
	* @return null
	*
	* This is where we queue the inline images associated with the message.
	*/
            	
	function messageQueued($id) {
		$imgtbl = $GLOBALS['tables']['inlineImagePlugin_image'];
		$msgtbl = $GLOBALS['tables']['inlineImagePlugin_msg'];
		
		$msgdata = loadMessageData($id);
		
		// Merge the message and template so we have all the images
		if ($msgdata["template"]) {
    		$req = Sql_Fetch_Row_Query("select template from {$GLOBALS["tables"]["template"]} where id = {$msgdata["template"]}");
    		$template = stripslashes($req[0]);
    		$msgdata['message'] = str_replace("[CONTENT]",$msgdata['message'], $template);
    	}
		
		// Collect the inline image tags
    	preg_match_all('/<img[^<>]+\Winline(?:\W|[^<>])*>/Ui', $msgdata['message'], $match);
		
		//Store everything needed for rapid processing of messages
		foreach ($match[0] as $val) {
			$src = $this->getAttribute($val, "src");
    		$fcontents = file_get_contents($src);
    		$hsh = sha1($fcontents);	// Use a checksum to distinguish different files with same name
    		$filename = basename ($src);
    		$query = sprintf("select imgid, cid, local_name from %s where filename='%s' and cksum='%s'", $imgtbl, $filename, $hsh);
    		if (!($row = Sql_Fetch_Row_Query($query))) {
    			$localfile = $this->getTempFilename($filename);	// File name in image directory
    			file_put_contents($localfile, $fcontents);
    			$type = $this->getMimeType(pathInfo($src, PATHINFO_EXTENSION), $localfile);
    			$cid = md5(uniqid(rand(), true));
    			$query = sprintf("insert into %s (file_name, cksum, local_name, type, cid) values ('%s', '%s', '%s', '%s', %s)", $imgtbl, sql_escape($filename), sql_escape($hash), sql_escape($localfile), sql_escape($type), sql_escape($cid));
    			if (Sql_Query($query)) {
    				$query = sprintf("select imgid from %s where filename='%s' and cksum='%s'", $imgtbl, $filename, $hsh);
    				$row = Sql_Fetch_Row_Query($query);
    				$imgid = $row[0];
    			}		
    		} else if (!file_exists($row[2])) { // We've had the image before, but it has 
    											// been stored in the plugin only as a temporary file
    			$localfile = $this->getTempFilename($filename);
    			file_put_contents($localfile, $fcontents);
    			$query = sprintf("update %s set local_name='%s' where imgid=%d", $imgtbl, $localfile, $row[0]);
    			Sql_Query($query);
    		} else {
    			$imgid = $row[0];
    			$cid = $row[1];
    		}
    		$srcstr = $this->getAttribute($val, "src", 0);
    		$imgtag = str_replace($srcstr, 'src="cid:' . $cid . '"', $val);
    		$query = sprintf("insert into %s values (%d, '%d', '%s', '%s')", $msgtbl, $id, $imgid, sql_escape($imgtag));
			Sql_Query($query);	
    	}
	
  	} 
  	
  	/* messageReQueued
  	* called when a message is placed back in the queue
	* @param integer id message id
   	* @return null
   	*/

  	function messageReQueued($id) {
  		$this->messageQueued($id);	// We don't care whether the message has been sent before
  	}
  	
  	/*
	* campaignStarted
	* called when sending of a campaign starts
	* @param array messagedata - associative array with all data for campaign
	* @return null
	*
	* Here is where we cache the image data that will be used in constructing the message
	*/

  	function campaignStarted($messagedata = array()) {
  		$this->curid = $messagedata['id'];
  		$msgtbl = $GLOBALS['tables']['inlineImagePlugin_msg'];
  		$imgtbl = $GLOBALS['tables']['inlineImagePlugin_image'];
  		
		$query = sprintf('select imagetag, cid, type, file_name, local_name from %s natural join %s where id = %d', $msgtbl, $imgtbl, $this->curid);
  		$result = Sql_Query($query);
  		$i = 0;
  		while ($row = Sql_Fetch_Assoc($result)) {
  			$this->cache[$this->curid][$i] = $row;
  			$this->cache[$this->curid][$i]['contents'] = file_get_contents($row['local_name']);
  			$i++;
  		}
  	} 
  	
	/* 
   	* parseOutgoingTextMessage
   	* @param integer messageid: ID of the message
   	* @param string  content: entire text content of a message going out
   	* @param string  destination: destination email
   	* @param array   userdata: associative array with data about user
   	* @return string parsed content
   	*
   	* No images in the text version of the message, so nothing to do
   	*/
  	function parseOutgoingTextMessage($messageid, $content, $destination, $userdata = null) {
    	return $content;
  	}

  	/* 
   	* parseOutgoingHTMLMessage
   	* @param integer messageid: ID of the message
  	* @param string  content: entire text content of a message going out
   	* @param string  destination: destination email
   	* @param array   userdata: associative array with data about user
   	* @return string parsed content
   	*/
  	function parseOutgoingHTMLMessage($messageid, $content, $destination, $userdata = null) {
  		
  		// In regex below, must account for possible substitution of spaces by &nbsp;
    	preg_match_all('/<img[^<>]+\Winline(?:\W|[^<>])*>/Ui', $content, $match);
    	for ($i = 0; $i < sizeof($match[0]); $i++) { // And replace them
  				$content = str_replace($match[0][$i], $this->cache[$messageid][$i]['imagetag'], $content);
  		}
    	return $content;
    }
  	
  	 /* processQueueStart
   	* called at the beginning of processQueue, after the process was locked
   	* @param none
   	* @return null
   	*/
  	function processQueueStart() {
  		$this->processing_queue = true;
  	}
  	
  	/* messageQueueFinished
   	* called when a sending of the queue has finished
   	* @return null
   	*/
  	function messageQueueFinished() {
  		$this->processing_queue = false;
  		
  		// The image directory files are only temporary; remove them
  		$imgdir = $this->coderoot . 'images/';
  		$filelist = scandir($imgdir);
  		foreach ($filelist as $thefile)
  			unlink ($imgdir . $thefile);
  	}

	/**
   	* messageHeaders
   	*
	* return headers for the message to be added, as "key => val"
   	*
   	* @param object $mail
   	* @return array (headeritem => headervalue)
   	*/
  	function messageHeaders($mail) {
  	
  		if (!$this->processing_queue)	// Administrative message?
  			return;
  		
  		$imgs = $this->cache[$this->curid];
  		for($i=0; $i< sizeof($imgs); $i++) {
  		
  			// Borrowed from the add_html_image() method of the PHPlistMailer class
  			if (method_exists($mail,'AddEmbeddedImageString')) {
        		$mail->AddEmbeddedImageString($imgs[$i]['contents'], $imgs[$i]['cid'], $imgs[$i]['file_name'], $mail->encoding, $imgs[$i]['type']);
      		} elseif (method_exists($mail,'AddStringEmbeddedImage')) {
        	## PHPMailer 5.2.5 and up renamed the method
        	## https://github.com/Synchro/PHPMailer/issues/42#issuecomment-16217354
        		$mail->AddStringEmbeddedImage($imgs[$i]['contents'], $imgs[$i]['cid'], $imgs[$i]['file_name'], $mail->encoding, $imgs[$i]['type']);
      		} elseif (isset($mail->attachment) && is_array($mail->attachment)) {
        	// Append to $attachment array
        		$cur = count($mail->attachment);
        		$mail->attachment[$cur][0] = $imgs[$i]['contents'];
        		$mail->attachment[$cur][1] = $imgs[$i]['file_name'];
        		$mail->attachment[$cur][2] = $imgs[$i]['file_name'];
        		$mail->attachment[$cur][3] = 'base64';
        		$mail->attachment[$cur][4] = $imgs[$i]['type'];
        		$mail->attachment[$cur][5] = true; // isStringAttachment
        		$mail->attachment[$cur][6] = "inline";
        		$mail->attachment[$cur][7] = $imgs[$i]['cid'];
      		} else {
        		logEvent("phpMailer needs patching to be able to use inline images");
       			print Error("phpMailer needs patching to be able to use inline images");
        	}
        } 
        return '';
  	}
}