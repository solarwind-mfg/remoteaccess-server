<?php
defined('EMONCMS_EXEC') or die('Restricted access');

function remoteaccess_userlink($mysqli,$username,$password) {

    if (!$stmt = $mysqli->prepare("SELECT username FROM users WHERE username=?")) {
        // the structure of the database doesn't match the prepared statement
        return array('success'=>false, 'message'=>"Precondition Failed", 'code'=>412);
    }

    // if mysql connection available find the user
    $stmt->bind_param("s",$username);
    $stmt->execute();
    $stmt->bind_result($userData_username);
    $result = $stmt->fetch();
    $stmt->close();

    if (!$result) {
    
        $content = json_decode(http_request("POST", "https://emoncms.org/user/auth.json", array(
            "username" => $username,
            "password" => $password,
        )));
        if (!isset($content->success)) return array('success'=>false, 'message'=>"Server error");
        if (isset($content->success) && $content->success === false) return array('success'=>false, 'message'=>"Emoncms.org account does not exist");        
        
        $email = "";
        $userid = $content->userid;
        $apikey_read = $content->apikey_read;
        $apikey_write = $content->apikey_write;
                    
        // Auto register on mqtt server
        $hash = hash('sha256', $password);
        $salt = md5(uniqid(mt_rand(), true));
        $pwhash = hash('sha256', $salt . $hash);
        
        $stmt = $mysqli->prepare("INSERT INTO users ( id, username, password, email, salt ,apikey_read, apikey_write, admin) VALUES (?,?,?,?,?,?,?,0)");
        $stmt->bind_param("issssss", $userid, $username, $pwhash, $email, $salt, $apikey_read, $apikey_write);
        $result = $stmt->execute();
        $stmt->close();
        if (!$result) return array('success'=>false, 'message'=>_("Error creating user"));
        
        include "Modules/remoteaccess/mqtt_hash.php";
        $mqtthash = create_hash($password);
        
        // insert new user into users table
        $stmt = $mysqli->prepare("INSERT INTO remoteaccess_users ( id, username, pw, super) VALUES (?,?,?,0)");
        $stmt->bind_param("iss",$userid,$username,$mqtthash);
        $result = $stmt->execute();
        $stmt->close();
        if (!$result) return array('success'=>false, 'message'=>_("Error adding user to remoteaccess user list"));
        
        // if new user successful add the user to the access control list.
        // access to only the topic (or sub topics) with their username is granted
        $topic = "user/$username/#";
        $stmt = $mysqli->prepare("INSERT INTO remoteaccess_acls (username, topic, rw) VALUES (?,?,2)");
        $stmt->bind_param("ss", $username, $topic);
        $result = $stmt->execute();
        $stmt->close();
        if (!$result) return array('success'=>false, 'message'=>_("Error adding user to remoteaccess access list"));
    }
    
    return array('success'=>true);
}
