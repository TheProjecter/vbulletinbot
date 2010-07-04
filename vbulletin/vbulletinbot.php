<?php
//-----------------------------------------------------------------------------
// $RCSFile: vbotservice.php $ $Revision: 1.19 $
// $Date: 2010/01/05 06:14:58 $
//-----------------------------------------------------------------------------

// hack for vbulletin 4.0 and CSRF Protection
$_POST["vb4"] = "";

// ######################## SET PHP ENVIRONMENT ###########################
error_reporting(E_ALL & ~E_NOTICE);

// ##################### DEFINE IMPORTANT CONSTANTS #######################
define('THIS_SCRIPT', 'vbulletinbot.php');
define('CSRF_PROTECTION', false); 
define('DIE_QUIETLY', 1);

// ########################## REQUIRE BACK-END ############################
require_once("nusoap/nusoap.php");

require_once('./global.php');
require_once(DIR . '/includes/class_dm.php');
require_once(DIR . '/includes/class_dm_threadpost.php');
require_once(DIR . '/includes/class_xml.php');
require_once(DIR . '/includes/functions_bigthree.php');
require_once(DIR . '/includes/functions_forumlist.php');
require_once(DIR . '/includes/functions_vbot.php');

// #######################################################################
// ######################## START MAIN SCRIPT ############################
// #######################################################################


 /**
 * ProcessSimpleType method
 * @param string $who name of the person we'll say hello to
 * @return string $helloText the hello  string
 */
function ProcessSimpleType($who) 
{
	$test = print_r($_SERVER,true);
	$who = "[$test]:$who";
	return "Hello $who";
}

function ErrorResult($text)
{
    global $vbulletin,$structtypes;
    
    $result['RemoteUser'] = ConsumeArray($vbulletin->userinfo,$structtypes['RemoteUser']);  
    $result['Code'] = 1;
    $result['Text'] = $text;

    return $result;    
}

function RegisterService($who)
{
	global $db,$vbulletin,$server;
	$result = array();
	
	if (!$vbulletin->options['vbb_serviceonoff'])
	{
		$result['Code'] = 1;
		$result['Text'] = 'vbb_service_turned_off';
	}	
	else if ($vbulletin->options['vbb_servicepw'] != $_SERVER['PHP_AUTH_PW'])
	{
		$result['Code'] = 1;
		$result['Text'] = 'vbb_invalid_servicepw';
	}
	else
	{
        $userid = fetch_userid_by_service($who['ServiceName'],$who['Username']);

        if (empty($userid) || $userid <= 0)
        {
            $result['Code'] = 1;
            $result['Text'] = 'invalid_user';
        }
        else
        {
            unset($vbulletin->userinfo);
            $vbulletin->userinfo = fetch_userinfo($userid);
            $permissions = cache_permissions($vbulletin->userinfo);            
            
		    // everything is ok
		    $result['Code'] = 0;
        }
	}
	
	return $result;
}

function GetPostByIndex($who,$threadid,$index)
{
    global $db,$vbulletin,$server,$structtypes,$lastpostarray;
    
    $result = RegisterService($who);
    if ($result['Code'] != 0)
    {
        return $result;
    }    
    
    if ($index > 0)
    {
        $index -= 1;
        $postinfo = $db->query_first("SELECT * FROM ". TABLE_PREFIX ."post as post WHERE (threadid = $threadid) ORDER BY dateline ASC LIMIT $index,1");
        
        if (is_array($postinfo))
        {
            $postinfo['pagetext'] = strip_bbcode($postinfo['pagetext'],true,false,false);  
            $retval['Post'] = ConsumeArray($postinfo,$structtypes['Post']);    
        }
    
        if ($postinfo['postid'] > 0)
        {
            $threadinfo = fetch_threadinfo($postinfo['threadid']);
            $foruminfo = fetch_foruminfo($threadinfo['forumid'],false);
            mark_thread_read($threadinfo, $foruminfo, $vbulletin->userinfo['userid'], $postinfo['dateline']);
        }
    }    
    
    $result['RemoteUser'] = ConsumeArray($vbulletin->userinfo,$structtypes['RemoteUser']); 
    $retval['Result'] = $result;
   
    return $retval;    
    
}

function GetIMNotifications($dodelete)
{
    global $db,$vbulletin,$server,$structtypes,$lastpostarray;
    
    $result = array();
    if (!$vbulletin->options['vbb_serviceonoff'])
    {
        $result['Code'] = 1;
        $result['Text'] = 'vbb_service_turned_off';
        return array('Result'=>$result);
    }    
    else if ($vbulletin->options['vbb_servicepw'] != $_SERVER['PHP_AUTH_PW'])
    {
        $result['Code'] = 1;
        $result['Text'] = 'vbb_invalid_servicepw';
        return array('Result'=>$result);
    }   

    $query = "
        SELECT 
            imnotification.*, 
            post.*, 
            thread.*, 
            forum.*, 
            post.dateline as postdateline,
            thread.title as threadtitle,
            user.username AS newpostusername,
            user.instantimnotification AS instantimnotification,  
            user.instantimscreenname AS instantimscreenname,  
            user.instantimservice AS instantimservice
        FROM " . TABLE_PREFIX . "imnotification AS imnotification 
        LEFT JOIN " . TABLE_PREFIX . "user AS user ON (imnotification.userid = user.userid)
        LEFT JOIN " . TABLE_PREFIX . "post AS post ON (imnotification.postid = post.postid)
        LEFT JOIN " . TABLE_PREFIX . "thread AS thread ON (post.threadid = thread.threadid)
        LEFT JOIN " . TABLE_PREFIX . "forum AS forum ON (thread.forumid = forum.forumid)

        ORDER By imnotification.dateline ASC 
    ";
    
    $notificationlist = array();
    
    $postnotifications = $db->query_read_slave($query);        
    while ($notification = $db->fetch_array($postnotifications))
    {    
        $notification['pagetext'] = strip_bbcode($notification['pagetext'],true,false,false);

        $temp['IMNotificationInfo'] = ConsumeArray($notification,$structtypes['IMNotificationInfo']);          
        $temp['Thread'] = ConsumeArray($notification,$structtypes['Thread']);           
        $temp['Post'] = ConsumeArray($notification,$structtypes['Post']);           
        $temp['Forum'] = ConsumeArray($notification,$structtypes['Forum']);           
        
        if ($dodelete)
        {
            $db->query_write("DELETE FROM " . TABLE_PREFIX . "imnotification WHERE (imnotificationid = $notification[imnotificationid]);");
        }
        
        array_push($notificationlist,$temp);
    }    
                                  
    $result['Code'] = 0;
    $result['Text'] = print_r($notificationlist,true);
    $retval['Result'] = $result;
    $retval['IMNotificationList'] = $notificationlist;
    
    return $retval;
}

function GetThread($who,$threadid)
{
    global $db,$vbulletin,$server,$structtypes,$lastpostarray;
    
    $result = RegisterService($who);
    if ($result['Code'] != 0)
    {
        return $result;
    }   
    
    $threadinfo = $thread = fetch_threadinfo($threadid);    
    $forum = fetch_foruminfo($thread['forumid']);
    $foruminfo =& $forum;    
    
    // check forum permissions
    $forumperms = fetch_permissions($thread['forumid']);
    if (!($forumperms & $vbulletin->bf_ugp_forumpermissions['canview']) OR !($forumperms & $vbulletin->bf_ugp_forumpermissions['canviewthreads']))
    {
        print_error_xml('no_permission_fetch_threadxml');
    }
    if (!($forumperms & $vbulletin->bf_ugp_forumpermissions['canviewothers']) AND ($thread['postuserid'] != $vbulletin->userinfo['userid'] OR $vbulletin->userinfo['userid'] == 0))
    {
        print_error_xml('no_permission_fetch_threadxml');
    }    
    
    // *********************************************************************************
    // check if there is a forum password and if so, ensure the user has it set
    verify_forum_password($foruminfo['forumid'], $foruminfo['password']);        
    
    $userid = $vbulletin->userinfo['userid'];
    $threadssql = "
        SELECT 
            thread.*,
            threadread.readtime AS threadread,
            forumread.readtime as forumread,
            subscribethread.subscribethreadid AS subscribethreadid
        FROM " . TABLE_PREFIX . "thread AS thread    
        LEFT JOIN " . TABLE_PREFIX . "threadread AS threadread ON (threadread.threadid = thread.threadid AND threadread.userid = $userid)         
        LEFT JOIN " . TABLE_PREFIX . "forumread AS forumread ON (thread.forumid = forumread.forumid AND forumread.userid = $userid)         
        LEFT JOIN " . TABLE_PREFIX . "subscribethread AS subscribethread ON (thread.threadid = subscribethread.threadid AND subscribethread.userid = $userid)        
        WHERE thread.threadid IN (0$threadid)
        ORDER BY lastpost DESC
    ";
        
    $thread = $db->query_first($threadssql);     
    
    // TODO: Remove this HACK!
    $thread['threadtitle'] = $thread['title'];
    
    $retval['Thread'] = ConsumeArray($thread,$structtypes['Thread']);                 
    
    $result['RemoteUser'] = ConsumeArray($vbulletin->userinfo,$structtypes['RemoteUser']); 
    $retval['Result'] = $result;
   
    return $retval;     
}

function ListForums($who,$forumid)
{
    global $db,$vbulletin,$server,$structtypes,$lastpostarray;
    
    $result = RegisterService($who);
    if ($result['Code'] != 0)
    {
        return $result;
    }

    $userid = $vbulletin->userinfo['userid'];
    //$xml = new XMLexporter($vbulletin);
    
    // ### GET FORUMS & MODERATOR iCACHES ########################
    cache_ordered_forums(1,1);
    if (empty($vbulletin->iforumcache))
    {
        $forums = $vbulletin->db->query_read_slave("
            SELECT forumid, title, link, parentid, displayorder, title_clean, description, description_clean,
            (options & " . $vbulletin->bf_misc_forumoptions['cancontainthreads'] . ") AS cancontainthreads
            FROM " . TABLE_PREFIX . "forum AS forum
            WHERE displayorder <> 0 AND
            password = '' AND
            (options & " . $vbulletin->bf_misc_forumoptions['active'] . ")
            ORDER BY displayorder
        ");
        
        $vbulletin->iforumcache = array();
        while ($forum = $vbulletin->db->fetch_array($forums))
        {
            $vbulletin->iforumcache["$forum[parentid]"]["$forum[displayorder]"]["$forum[forumid]"] = $forum;
        }
        unset($forum);
        $vbulletin->db->free_result($forums);
    }    

    // define max depth for forums display based on $vbulletin->options[forumhomedepth]
    define('MAXFORUMDEPTH', 1);
    
    if (is_array($vbulletin->iforumcache["$forumid"]))
    {
        $childarray = $vbulletin->iforumcache["$forumid"];
    }
    else
    {
        $childarray = array($vbulletin->iforumcache["$forumid"]);
    }
    
    if (!is_array($lastpostarray))
    {
        fetch_last_post_array();
    }    
    
    // add the current forum info
    // get the current location title
    $current = $db->query_first("SELECT title FROM " . TABLE_PREFIX . "forum AS forum WHERE (forumid = $forumid)");
    if (strlen($current['title']) == 0)
    {
        $current['title'] = 'INDEX';
    }

    $forum = fetch_foruminfo($forumid);
    $lastpostinfo = $vbulletin->forumcache["$lastpostarray[$forumid]"];    
    $isnew = fetch_forum_lightbulb($forumid, $lastpostinfo, $forum);
    
    $curforum['ForumID'] = $forumid;
    $curforum['Title'] = $current['title'];
    $curforum['IsNew'] = $isnew == "new";
    $curforum['IsCurrent'] = true;
    
    $forumlist = array();
    
    foreach ($childarray as $subforumid)
    {
        // hack out the forum id
        $forum = fetch_foruminfo($subforumid);
        if (!$forum['displayorder'] OR !($forum['options'] & $vbulletin->bf_misc_forumoptions['active']))
        {
            continue;
        }    

        $forumperms = $vbulletin->userinfo['forumpermissions']["$subforumid"];
        if (!($forumperms & $vbulletin->bf_ugp_forumpermissions['canview']) AND ($vbulletin->forumcache["$subforumid"]['showprivate'] == 1 OR (!$vbulletin->forumcache["$subforumid"]['showprivate'] AND !$vbulletin->options['showprivateforums'])))
        { // no permission to view current forum
            continue;
        }    
        
        $lastpostinfo = $vbulletin->forumcache["$lastpostarray[$subforumid]"];    
        $isnew = fetch_forum_lightbulb($forumid, $lastpostinfo, $forum);            
        
        $tempforum['ForumID'] = $forum['forumid'];
        $tempforum['Title'] = $forum['title'];
        $tempforum['IsNew'] = $isnew == "new";
        $tempforum['IsCurrent'] = false;
        array_push($forumlist,$tempforum);
        unset($tempforum);
    }        
    
    $result['RemoteUser'] = ConsumeArray($vbulletin->userinfo,$structtypes['RemoteUser']); 
    
    $retval['Result'] = $result;
    $retval['CurrentForum'] = $curforum;
    $retval['ForumList'] = $forumlist;
    
    return $retval;
}

function ListParentForums($who,$forumid)
{
    global $db,$vbulletin,$server,$structtypes,$lastpostarray;
    
    $tempinfo = fetch_foruminfo($forumid);
    
    if ($forumid == -1)    
    {
        return ListForums($who,-1);
    }
    
    if ($tempinfo['parentid'] != -1)
    {
        $info = fetch_foruminfo($tempinfo['parentid']);
        return ListForums($who,$info['forumid']);        
    }
    else
    {
        return ListForums($who,-1);        
    }      
}

function ListPosts($who,$threadid,$pagenumber,$perpage)
{
    global $db,$vbulletin,$server,$structtypes,$lastpostarray;
    
    $result = RegisterService($who);
    if ($result['Code'] != 0)
    {
        return $result;
    }    
    
    // *********************************************************************************
    // get thread info
    $threadinfo = $thread = fetch_threadinfo($threadid);
    
    if (!($thread['threadid'] > 0))
    {
        print_error_xml('invalid_threadid_fetch_postsxml');            
    }
    
    // *********************************************************************************
    // get forum info
    $forum = fetch_foruminfo($thread['forumid']);
    $foruminfo =& $forum;

    // *********************************************************************************
    // check forum permissions
    $forumperms = fetch_permissions($thread['forumid']);
    if (!($forumperms & $vbulletin->bf_ugp_forumpermissions['canview']) OR !($forumperms & $vbulletin->bf_ugp_forumpermissions['canviewthreads']))
    {
        print_error_xml('no_permission_fetch_postsxml');
    }
    if (!($forumperms & $vbulletin->bf_ugp_forumpermissions['canviewothers']) AND ($thread['postuserid'] != $vbulletin->userinfo['userid'] OR $vbulletin->userinfo['userid'] == 0))
    {
        print_error_xml('no_permission_fetch_postsxml');
    }
    
    // *********************************************************************************
    // check if there is a forum password and if so, ensure the user has it set
    verify_forum_password($foruminfo['forumid'], $foruminfo['password']);    
    
    
    // TODO: the client expects 'threadtitle', remove this HACK
    $threadinfo['threadtitle'] = $threadinfo['title'];
    
    $retval['Thread'] = ConsumeArray($threadinfo,$structtypes['Thread']);
    
    $limitlower = ($pagenumber - 1) * $perpage;
    $userid = $vbulletin->userinfo['userid'];
    
    $postssql = "
        SELECT 
            *,
            post.dateline as dateline,
            threadread.readtime as threadread,
            forumread.readtime as forumread
        FROM " . TABLE_PREFIX . "post as post 
        LEFT JOIN " . TABLE_PREFIX . "thread AS thread ON (thread.threadid = post.threadid)
        LEFT JOIN " . TABLE_PREFIX . "threadread AS threadread ON (threadread.threadid = post.threadid AND threadread.userid = $userid) 
        LEFT JOIN " . TABLE_PREFIX . "forumread AS forumread ON (thread.forumid = forumread.forumid AND forumread.userid = $userid) 
        WHERE 
            post.threadid = $threadid 
            AND post.visible = 1 
        ORDER By post.dateline ASC 
        LIMIT $limitlower, $perpage        
    ";
    
    $postlist = array();
    $posts = $db->query_read_slave($postssql);        
    while ($post = $db->fetch_array($posts))
    {    
        $post['isnew'] = true;        
        if ($post['threadread'] >= $post['dateline'] || (TIMENOW - ($vbulletin->options['markinglimit'] * 86400)) >= $post['dateline'] )
        {
            $post['isnew'] = false;
        }        
        
        $post['pagetext'] = strip_bbcode($post['pagetext'],true,false,false); 
        array_push($postlist,ConsumeArray($post,$structtypes['Post']));
    }    
    
    $retval['PostList'] = $postlist;  
    
    $result['RemoteUser'] = ConsumeArray($vbulletin->userinfo,$structtypes['RemoteUser']);   
    $retval['Result'] = $result;    
    return $retval;
}

function ListThreads($who,$forumid,$pagenumber,$perpage)
{
    global $db,$vbulletin,$server,$structtypes,$lastpostarray;
    
    $result = RegisterService($who);
    if ($result['Code'] != 0)
    {
        return $result;
    }

    // get the total threads count    
    $threadcount = $db->query_first("SELECT threadcount FROM " . TABLE_PREFIX . "forum WHERE (forumid = $forumid);");
    
    if ($threadcount > 0)
    {
        $forumperms = fetch_permissions($forumid);
        if (!($forumperms & $vbulletin->bf_ugp_forumpermissions['canview']))
        {
            // TODO: handle this properly
            //print_error_xml('no_permission_fetch_threadsxml');
        }            
        
        $userid = $vbulletin->userinfo['userid'];
        $limitlower = ($pagenumber - 1) * $perpage;
        
        $getthreadidssql = ("
            SELECT 
                thread.threadid, 
                thread.lastpost, 
                thread.lastposter, 
                thread.lastpostid, 
                thread.replycount, 
                IF(thread.views<=thread.replycount, thread.replycount+1, thread.views) AS views
            FROM " . TABLE_PREFIX . "thread AS thread
            WHERE forumid = $forumid
                AND sticky = 0
                AND visible = 1
            ORDER BY 
                lastpost DESC         
            LIMIT $limitlower, $perpage
        ");    
    
        $getthreadids = $db->query_read_slave($getthreadidssql);
        
        $ids = '';
        while ($thread = $db->fetch_array($getthreadids))
        {
            $ids .= ',' . $thread['threadid'];
        }
    
            $threadssql = "
                SELECT 
                    thread.threadid, 
                    thread.title AS threadtitle, 
                    thread.forumid, 
                    thread.lastpost, 
                    thread.lastposter, 
                    thread.lastpostid, 
                    thread.replycount,
                    threadread.readtime AS threadread,
                    forumread.readtime as forumread,
                    subscribethread.subscribethreadid AS subscribethreadid
                FROM " . TABLE_PREFIX . "thread AS thread    
                LEFT JOIN " . TABLE_PREFIX . "threadread AS threadread ON (threadread.threadid = thread.threadid AND threadread.userid = $userid)         
                LEFT JOIN " . TABLE_PREFIX . "forumread AS forumread ON (thread.forumid = forumread.forumid AND forumread.userid = $userid)         
                LEFT JOIN " . TABLE_PREFIX . "subscribethread AS subscribethread ON (thread.threadid = subscribethread.threadid AND subscribethread.userid = $userid)        
                WHERE thread.threadid IN (0$ids)
                ORDER BY lastpost DESC
            ";
            
        $threads = $db->query_read_slave($threadssql);        
        $threadlist = array();
    
        while ($thread = $db->fetch_array($threads))
        {   
            $thread['issubscribed'] = $thread['subscribethreadid'] > 0;
            
            $thread['isnew'] = true;        
            if ($thread['forumread'] >= $thread['lastpost'] || $thread['threadread'] >= $thread['lastpost'] || (TIMENOW - ($vbulletin->options['markinglimit'] * 86400)) > $thread['lastpost'] )
            {
                $thread['isnew'] = false;
            }

            $thread = ConsumeArray($thread,$structtypes['Thread']);            
            array_push($threadlist,$thread);
        }        
    }      
    
    $result['RemoteUser'] = ConsumeArray($vbulletin->userinfo,$structtypes['RemoteUser']);   
    $retval['Result'] = $result;
    $retval['ThreadList'] = $threadlist;
    $retval['ThreadCount'] = $threadcount['threadcount'];
    
    return $retval;
}

function MarkForumRead($who,$forumid)
{
    global $db,$vbulletin,$server,$structtypes,$lastpostarray;
    
    $result = RegisterService($who);
    if ($result['Code'] != 0)
    {
        return $result;
    }   

    $foruminfo = fetch_foruminfo($forumid);
    mark_forum_read($foruminfo,$vbulletin->userinfo['userid'],TIMENOW);

    $result['RemoteUser'] = ConsumeArray($vbulletin->userinfo,$structtypes['RemoteUser']); 
    $retval['Result'] = $result;
   
    return $retval;      
}

function MarkThreadRead($who,$threadid)
{
    global $db,$vbulletin,$server,$structtypes,$lastpostarray;
    
    $result = RegisterService($who);
    if ($result['Code'] != 0)
    {
        return $result;
    }   

    $threadinfo = fetch_threadinfo($threadid);
    $foruminfo = fetch_foruminfo($threadinfo['forumid']);

    mark_thread_read($threadinfo,$foruminfo,$vbulletin->userinfo['userid'],TIMENOW);    
    
    $result['RemoteUser'] = ConsumeArray($vbulletin->userinfo,$structtypes['RemoteUser']); 
    $retval['Result'] = $result;
   
    return $retval;      
}

function PostReply($who,$threadid,$pagetext)
{
    global $db,$vbulletin,$server,$structtypes,$lastpostarray;

    $result = RegisterService($who);
    if ($result['Code'] != 0)
    {
        return $result;
    }     

    $threadinfo = fetch_threadinfo($threadid);
    $foruminfo = fetch_foruminfo($threadinfo['forumid'],false);

    $postdm = new vB_DataManager_Post($vbulletin, ERRTYPE_STANDARD);
    $postdm->set_info('skip_maximagescheck', true);
    $postdm->set_info('forum', $foruminfo);
    $postdm->set_info('thread', $threadinfo);  
    $postdm->set('threadid', $threadid);    
    $postdm->set('userid', $vbulletin->userinfo['userid']);    
    $postdm->set('pagetext', $pagetext);
    $postdm->set('allowsmilie', 1);
    $postdm->set('visible', 1);
    $postdm->set('dateline', TIMENOW);        
    
    $postdm->pre_save();
    $postid = 0;
    
    if (count($postdm->errors) > 0)
    { // pre_save failed
        return ErrorResult('pre_save_failed_thread_reply');
    }    
    else
    {
        $postid = $postdm->save();
        
        require_once('./includes/functions_databuild.php'); 
        build_thread_counters($threadinfo['threadid']); 
        build_forum_counters($foruminfo['forumid']);                    
        correct_forum_counters($threadinfo['threadid'], $foruminfo['forumid']);        
        
        mark_thread_read($threadinfo, $foruminfo, $vbulletin->userinfo['userid'], TIMENOW);
    }    
    
    $retval['PostID'] = $postid;
    $retval['RemoteUser'] = ConsumeArray($vbulletin->userinfo,$structtypes['RemoteUser']); 
    
    $result['Code'] = 1;
    $retval['Result'] = $result;
    
    return $retval;      
}

function SetIMNotification($who,$on)
{
    global $db,$vbulletin,$server,$structtypes,$lastpostarray;
    
    $result = RegisterService($who);
    if ($result['Code'] != 0)
    {
        return $result;
    }   

    $userid = $vbulletin->userinfo['userid'];
    $onoff = 0;
    if ($on)
    {
        $onoff = 1;
    }
    
    $db->query_write("
        UPDATE " . TABLE_PREFIX . "user 
        SET instantimnotification=$onoff
        WHERE (userid = $userid);
    ");   
    
    $result['RemoteUser'] = ConsumeArray($vbulletin->userinfo,$structtypes['RemoteUser']); 
    $retval['Result'] = $result;
   
    return $retval;      
}

function SubscribeThread($who,$threadid)
{
    global $db,$vbulletin,$server,$structtypes,$lastpostarray;
    
    $result = RegisterService($who);
    if ($result['Code'] != 0)
    {
        return $result;
    }   
    
    $threadinfo = fetch_threadinfo($threadid);
    $foruminfo = fetch_foruminfo($threadinfo['forumid'],false);
    
    if (!$foruminfo['forumid'])
    {
        return ErrorResult("invalid_forumid_subscribe_thread");
    }
    
    $forumperms = fetch_permissions($foruminfo['forumid']);
    if (!($forumperms & $vbulletin->bf_ugp_forumpermissions['canview']))
    {
        return ErrorResult("no_forum_permission_subscribe_thread");
    }    
    
    if (!$foruminfo['allowposting'] OR $foruminfo['link'] OR !$foruminfo['cancontainthreads'])
    {
        return ErrorResult("forum_closed_subscribe_thread");
    }    
    
    if (!verify_forum_password($foruminfo['forumid'], $foruminfo['password'], false))
    {
        return ErrorResult("invalid_forum_password_subscribe_thread");
    }
    
    if ($threadinfo['threadid'] > 0)
    {
        if ((!$threadinfo['visible'] AND !can_moderate($threadinfo['forumid'], 'canmoderateposts')) OR ($threadinfo['isdeleted'] AND !can_moderate($threadinfo['forumid'], 'candeleteposts')))
        {
            return ErrorResult('cannot_view_thread_subscribe_thread');    
        }        
        
        if (!($forumperms & $vbulletin->bf_ugp_forumpermissions['canviewthreads']) OR (($vbulletin->userinfo['userid'] != $threadinfo['postuserid'] OR !$vbulletin->userinfo['userid']) AND !($forumperms & $vbulletin->bf_ugp_forumpermissions['canviewothers'])))
        {
            return ErrorResult("no_thread_permission_subscribe_thread");
        }        
        
        $emailupdate = 1; // Instant notification by email
        $folderid = 0; // Delfault folder
        
        /*insert query*/
        $db->query_write("
            REPLACE INTO " . TABLE_PREFIX . "subscribethread (userid, threadid, emailupdate, folderid, canview)
            VALUES (" . $vbulletin->userinfo['userid'] . ", $threadinfo[threadid], $emailupdate, $folderid, 1)
        ");        

        // TODO: remove this HACK!
        $threadinfo['threadtitle'] = $threadinfo['title'];
        $retval['Thread'] = ConsumeArray($threadinfo,$structtypes['Thread']);
    }
    else
    {
        return ErrorResult("invalid_threadid_subscribe_thread");        
    } 
    
    $result['RemoteUser'] = ConsumeArray($vbulletin->userinfo,$structtypes['RemoteUser']); 
    $retval['Result'] = $result;
   
    return $retval;  
}

function UnSubscribeThread($who,$threadid) 
{
     global $db,$vbulletin,$server,$structtypes,$lastpostarray;

    $result = RegisterService($who);
    if ($result['Code'] != 0)
    {
        return $result;
    }    
    
    if (is_numeric($threadid))
    { // delete this specific thread subscription
    
        $userid = $vbulletin->userinfo['userid'];
        if ($threadid > 0)
        {
            $db->query_write("
                DELETE FROM " . TABLE_PREFIX . "subscribethread 
                WHERE (threadid = $threadid AND userid = $userid);
            ");            
        }
        else if ($threadid == -1)
        {
            $db->query_write("
                DELETE FROM " . TABLE_PREFIX . "subscribethread 
                WHERE (userid = $userid);
            ");            
        }
    }
    else
    {
        return ErrorResult('invalid_threadid_unsubscribe_thread');        
    }    
    
    $retval['RemoteUser'] = ConsumeArray($vbulletin->userinfo,$structtypes['RemoteUser']); 
   
    return $retval;       
}

function WhoAmI($who)
{
	global $db,$vbulletin,$server,$structtypes;
	
	$result = RegisterService($who);
	if ($result['Code'] != 0)
	{
		return $result;
	}
	
	$retuser = ConsumeArray($vbulletin->userinfo,$structtypes['RemoteUser']);

	$result['Code'] = 0;
	$result['Text'] = '';
	$result['RemoteUser'] = ConsumeArray($vbulletin->userinfo,$structtypes['RemoteUser']); 
    
	return $result;
}

$namespace = $vbulletin->options['bburl'];

// create a new soap server
$server = new soap_server();

// configure our WSDL
$server->configureWSDL("VBotService","urn:VBotService");

// set our namespace
$server->wsdl->schemaTargetNamespace = $namespace;

// include service types and functions           
include (DIR . '/includes/types_vbot.php');
include (DIR . '/includes/services_vbot.php');

// Get our posted data if the service is being consumed
// otherwise leave this data blank.                
$HTTP_RAW_POST_DATA = isset($GLOBALS['HTTP_RAW_POST_DATA']) 
                ? $GLOBALS['HTTP_RAW_POST_DATA'] : '';

// pass our posted data (or nothing) to the soap service                    
$server->service($HTTP_RAW_POST_DATA);                
exit();
?>