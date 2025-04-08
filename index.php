<?php
// =============================================
// CONFIGURATION
// =============================================
define('DB_HOST', 'sql302.infinityfree.com');
define('DB_USER', 'if0_38635932');
define('DB_PASS', 'nqsu40lAtdxzlc');
define('DB_NAME', 'if0_38635932_dating_app');
define('UPLOAD_DIR', 'uploads/profile_pics/');

// Create uploads directory if it doesn't exist
if (!file_exists(UPLOAD_DIR)) {
    mkdir(UPLOAD_DIR, 0777, true);
}

// Start session
session_start();

// Create database connection and tables if they don't exist
try {
    $pdo = new PDO("mysql:host=".DB_HOST.";dbname=".DB_NAME, DB_USER, DB_PASS);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS users (
        id INT AUTO_INCREMENT PRIMARY KEY,
        username VARCHAR(50) NOT NULL UNIQUE,
        email VARCHAR(100) NOT NULL UNIQUE,
        password VARCHAR(255) NOT NULL,
        profile_pic VARCHAR(255),
        height_feet DECIMAL(3,1),
        age INT,
        skin_color VARCHAR(50),
        interests VARCHAR(255),
        gender ENUM('Male', 'Female', 'Non-binary', 'Other') NOT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    );
        
        CREATE TABLE IF NOT EXISTS messages (
            id INT AUTO_INCREMENT PRIMARY KEY,
            sender_id INT NOT NULL,
            receiver_id INT NOT NULL,
            body TEXT NOT NULL,
            is_read BOOLEAN DEFAULT FALSE,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (sender_id) REFERENCES users(id),
            FOREIGN KEY (receiver_id) REFERENCES users(id)
        );
        
        CREATE TABLE IF NOT EXISTS message_replies (
            id INT AUTO_INCREMENT PRIMARY KEY,
            message_id INT NOT NULL,
            sender_id INT NOT NULL,
            body TEXT NOT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (message_id) REFERENCES messages(id),
            FOREIGN KEY (sender_id) REFERENCES users(id)
        );
    ");
} catch (PDOException $e) {
    die("Connection failed: " . $e->getMessage());
}

// =============================================
// HELPER FUNCTIONS
// =============================================
function isLoggedIn() {
    return isset($_SESSION['user_id']);
}

//file compress function
//The number 50 represents the percentage to which the image is compressed
function compressImage($source, $destination, $quality = 50, $maxWidth = 800) {
    $info = getimagesize($source);
    
    if ($info['mime'] == 'image/jpeg') {
        $image = imagecreatefromjpeg($source);
    } elseif ($info['mime'] == 'image/png') {
        $image = imagecreatefrompng($source);
    } elseif ($info['mime'] == 'image/gif') {
        $image = imagecreatefromgif($source);
    } else {
        return false; // Unsupported image type
    }
    
    // Get current dimensions
    $width = imagesx($image);
    $height = imagesy($image);
    
    // Calculate new dimensions if needed
    if ($width > $maxWidth) {
        $newHeight = (int)($height * ($maxWidth / $width));
        $newWidth = $maxWidth;
        
        // Create new image with new dimensions
        $newImage = imagecreatetruecolor($newWidth, $newHeight);
        
        // Preserve transparency for PNG and GIF
        if ($info['mime'] == 'image/png' || $info['mime'] == 'image/gif') {
            imagecolortransparent($newImage, imagecolorallocatealpha($newImage, 0, 0, 0, 127));
            imagealphablending($newImage, false);
            imagesavealpha($newImage, true);
        }
        
        // Resize the image
        imagecopyresampled($newImage, $image, 0, 0, 0, 0, $newWidth, $newHeight, $width, $height);
        $image = $newImage;
    }
    
    // Save the compressed image
    if ($info['mime'] == 'image/jpeg') {
        imagejpeg($image, $destination, $quality);
    } elseif ($info['mime'] == 'image/png') {
        imagepng($image, $destination, 9 - round($quality / 10)); // PNG quality is 0-9
    } elseif ($info['mime'] == 'image/gif') {
        imagegif($image, $destination);
    }
    
    // Free up memory
    imagedestroy($image);
    
    return true;
}


function handleFileUpload($file) {
    if ($file && $file['error'] === UPLOAD_ERR_OK) {
        $fileExt = pathinfo($file['name'], PATHINFO_EXTENSION);
        $fileName = uniqid('profile_') . '.' . $fileExt;
        $tempPath = $file['tmp_name'];
        $filePath = UPLOAD_DIR . $fileName;
        
        // First compress the image
        if (compressImage($tempPath, $filePath)) {
            return $filePath;
        }
        
        // Fallback to regular upload if compression fails
        if (move_uploaded_file($tempPath, $filePath)) {
            return $filePath;
        }
    }
    return null;
}

function getProfilePic($path) {
    return $path && file_exists($path) ? $path : 'https://www.gravatar.com/avatar/'.md5(uniqid()).'?d=identicon&s=200';
}

function getUsers($currentUserId, $pdo) {
    $stmt = $pdo->prepare("
        SELECT id, username, email, profile_pic, height_feet, age, skin_color, interests, gender
        FROM users 
        WHERE id != ?
        ORDER BY username ASC
    ");
    $stmt->execute([$currentUserId]);
    return $stmt->fetchAll();
}

function getFilteredUsers($currentUserId, $interests, $pdo) {
    $interestConditions = [];
    $params = [$currentUserId];
    
    if (!empty($interests)) {
        $interestsArray = explode(',', $interests);
        foreach ($interestsArray as $interest) {
            $interestConditions[] = "interests LIKE ?";
            $params[] = "%$interest%";
        }
    }
    
    $sql = "SELECT id, username, email, profile_pic, height_feet, age, skin_color, interests 
            FROM users 
            WHERE id != ?";
            
    if (!empty($interestConditions)) {
        $sql .= " AND (" . implode(" OR ", $interestConditions) . ")";
    }
    
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetchAll();
}

function searchUsers($currentUserId, $searchParams, $pdo) {
    $conditions = ["id != ?"];
    $params = [$currentUserId];
    
    if (!empty($searchParams['search_term'])) {
        $conditions[] = "(username LIKE ? OR email LIKE ? OR interests LIKE ?)";
        $searchTerm = "%{$searchParams['search_term']}%";
        array_push($params, $searchTerm, $searchTerm, $searchTerm);
    }
    
    if (!empty($searchParams['age'])) {
        $conditions[] = "age = ?";
        $params[] = $searchParams['age'];
    }
    
    if (!empty($searchParams['skin_color'])) {
        $conditions[] = "skin_color = ?";
        $params[] = $searchParams['skin_color'];
    }
    
    if (!empty($searchParams['interests'])) {
        $interestsArray = explode(',', $searchParams['interests']);
        $interestConditions = [];
        foreach ($interestsArray as $interest) {
            $interestConditions[] = "interests LIKE ?";
            $params[] = "%$interest%";
        }
        $conditions[] = "(" . implode(" OR ", $interestConditions) . ")";
    }
    
    $sql = "SELECT id, username, email, profile_pic, height_feet, age, skin_color, interests 
            FROM users 
            WHERE " . implode(" AND ", $conditions);
    
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetchAll();
}

function getUnifiedInbox($userId, $pdo) {
    $stmt = $pdo->prepare("
        SELECT 
            m.id, 
            m.body, 
            m.created_at,
            m.is_read,
            u1.username as sender_username,
            u1.email as sender_email,
            u1.profile_pic as sender_pic,
            u2.username as receiver_username,
            u2.email as receiver_email,
            u2.profile_pic as receiver_pic,
            'message' as type,
            CASE WHEN m.receiver_id = ? THEN 'received' ELSE 'sent' END as direction
        FROM messages m
        JOIN users u1 ON m.sender_id = u1.id
        JOIN users u2 ON m.receiver_id = u2.id
        WHERE m.receiver_id = ? OR m.sender_id = ?
        
        UNION ALL
        
        SELECT 
            r.id, 
            r.body, 
            r.created_at,
            TRUE as is_read,
            u1.username as sender_username,
            u1.email as sender_email,
            u1.profile_pic as sender_pic,
            u2.username as receiver_username,
            u2.email as receiver_email,
            u2.profile_pic as receiver_pic,
            'reply' as type,
            CASE WHEN r.sender_id = ? THEN 'sent' ELSE 'received' END as direction
        FROM message_replies r
        JOIN messages m ON r.message_id = m.id
        JOIN users u1 ON r.sender_id = u1.id
        JOIN users u2 ON (m.sender_id = u2.id OR m.receiver_id = u2.id) AND u2.id != r.sender_id
        WHERE r.sender_id = ? OR m.receiver_id = ? OR m.sender_id = ?
        
        ORDER BY created_at DESC
    ");
    
    $stmt->execute([$userId, $userId, $userId, $userId, $userId, $userId, $userId]);
    return $stmt->fetchAll();
}

// =============================================
// FORM PROCESSING
// =============================================
$action = $_GET['action'] ?? (isLoggedIn() ? 'view_inbox' : 'login');
$error = '';
$success = '';

// Handle login
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $action === 'login') {
    $email = trim($_POST['email']);
    $password = trim($_POST['password']);
    
    $stmt = $pdo->prepare("SELECT * FROM users WHERE email = ?");
    $stmt->execute([$email]);
    $user = $stmt->fetch();
    
    if ($user && password_verify($password, $user['password'])) {
        $_SESSION['user_id'] = $user['id'];
        $_SESSION['username'] = $user['username'];
        $_SESSION['email'] = $user['email'];
        $_SESSION['profile_pic'] = $user['profile_pic'];
        $_SESSION['interests'] = $user['interests'];
        $success = 'Login successful!';
        $action = 'view_inbox';
    } else {
        $error = 'Invalid email or password';
    }
}

// Handle registration
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $action === 'register') {
    $username = trim($_POST['username']);
    $email = trim($_POST['email']);
    $password = trim($_POST['password']);
    $confirm_password = trim($_POST['confirm_password']);
    $profilePic = $_FILES['profile_pic'] ?? null;
    $height = isset($_POST['height']) ? floatval($_POST['height']) : null;
    $age = isset($_POST['age']) ? intval($_POST['age']) : null;
    $skinColor = trim($_POST['skin_color']) ?? null;
    $interests = isset($_POST['interests']) ? implode(',', $_POST['interests']) : null;
    $gender = trim($_POST['gender'] ?? null);

    // Validation
    $errors = [];
    if (empty($username)) $errors[] = 'Username is required';
    if (empty($email)) $errors[] = 'Email is required';
    if (empty($password)) $errors[] = 'Password is required';
    if ($password !== $confirm_password) $errors[] = 'Passwords do not match';
    if (strlen($password) < 6) $errors[] = 'Password must be at least 6 characters';
    if (!empty($height) && ($height < 3 || $height > 8)) $errors[] = 'Please enter a valid height between 3 and 8 feet';
    if (!empty($age) && ($age < 18 || $age > 120)) $errors[] = 'Please enter a valid age between 18 and 120';
    if (empty($gender)) {
        $errors[] = 'Gender is required';
    }
    
    if ($profilePic && $profilePic['error'] !== UPLOAD_ERR_OK && $profilePic['error'] !== UPLOAD_ERR_NO_FILE) {
        $errors[] = 'Profile picture upload error';
    }


    if (empty($errors)) {
        $profilePicPath = handleFileUpload($profilePic);
        $hashedPassword = password_hash($password, PASSWORD_DEFAULT);

        if ($profilePic && !$profilePicPath) {
            $errors[] = 'Failed to process profile picture. Please try another image.';
        }
        
        
        try {
            $stmt = $pdo->prepare("
            INSERT INTO users (
                username, email, password, profile_pic, 
                height_feet, age, skin_color, interests, gender
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
        ");
        $stmt->execute([
            $username, $email, $hashedPassword, $profilePicPath,
            $height, $age, $skinColor, $interests, $gender
        ]);
            
            $_SESSION['user_id'] = $pdo->lastInsertId();
            $_SESSION['username'] = $username;
            $_SESSION['email'] = $email;
            $_SESSION['profile_pic'] = $profilePicPath;
            $_SESSION['interests'] = $interests;
            $success = 'Registration successful!';
            $action = 'view_inbox';
        } catch (PDOException $e) {
            $error = $e->errorInfo[1] === 1062 ? 'Username or email already exists' : 'Registration failed';
        }
    } else {
        $error = implode('<br>', $errors);
    }
}

// Handle sending messages
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $action === 'send_message') {
    if (!isLoggedIn()) {
        header("Location: ?action=login");
        exit();
    }
    
    $receiverId = $_POST['receiver_id'];
    $body = trim($_POST['body']);
    
    if (empty($receiverId) || empty($body)) {
        $error = 'Please fill in all fields';
    } else {
        $stmt = $pdo->prepare("INSERT INTO messages (sender_id, receiver_id, body) VALUES (?, ?, ?)");
        if ($stmt->execute([$_SESSION['user_id'], $receiverId, $body])) {
            $success = 'Message sent successfully!';
            $action = 'view_inbox';
        } else {
            $error = 'Failed to send message';
        }
    }
}

// Handle sending replies
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['reply_message'])) {
    if (!isLoggedIn()) {
        header("Location: ?action=login");
        exit();
    }
    
    $messageId = $_POST['message_id'];
    $body = trim($_POST['reply_body']);
    
    if (empty($messageId) || empty($body)) {
        $error = 'Please enter your reply';
    } else {
        $stmt = $pdo->prepare("INSERT INTO message_replies (message_id, sender_id, body) VALUES (?, ?, ?)");
        if ($stmt->execute([$messageId, $_SESSION['user_id'], $body])) {
            $success = 'Reply sent successfully!';
            $action = 'view_inbox';
        } else {
            $error = 'Failed to send reply';
        }
    }
}

// Handle search
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $action === 'search_users') {
    if (!isLoggedIn()) {
        header("Location: ?action=login");
        exit();
    }
    
    $searchParams = [
        'search_term' => trim($_POST['search_term'] ?? ''),
        'age' => trim($_POST['age'] ?? ''),
        'skin_color' => trim($_POST['skin_color'] ?? ''),
        'interests' => isset($_POST['interests']) ? implode(',', $_POST['interests']) : ''
    ];
    
    $users = searchUsers($_SESSION['user_id'], $searchParams, $pdo);
    $action = 'compose'; // Show compose view with search results
}

// Handle logout
if ($action === 'logout') {
    session_destroy();
    header("Location: ?action=login");
    exit();
}

// Handle AJAX request for message details
if ($action === 'get_message_details' && isset($_GET['message_id'])) {
    if (!isLoggedIn()) {
        header('HTTP/1.1 401 Unauthorized');
        exit();
    }
    
    $stmt = $pdo->prepare("
        SELECT m.*, u1.username as sender_username, u1.profile_pic as sender_pic,
        u2.username as receiver_username, u2.profile_pic as receiver_pic
        FROM messages m 
        JOIN users u1 ON m.sender_id = u1.id 
        JOIN users u2 ON m.receiver_id = u2.id 
        WHERE m.id = ? AND (m.sender_id = ? OR m.receiver_id = ?)
    ");
    $stmt->execute([$_GET['message_id'], $_SESSION['user_id'], $_SESSION['user_id']]);
    $message = $stmt->fetch();
    
    if (!$message) {
        header('HTTP/1.1 404 Not Found');
        exit();
    }
    
    header('Content-Type: application/json');
    echo json_encode($message);
    exit();
}

// =============================================
// HTML OUTPUT
// =============================================
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Pink Dating App</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/materialize/1.0.0/css/materialize.min.css">
    <link href="https://fonts.googleapis.com/icon?family=Material+Icons" rel="stylesheet">
    <style>
        :root {
            --primary-color: #ff69b4;
            --primary-light: #ffb6c1;
            --primary-dark: #db7093;
            --secondary-color: #ff1493;
            --accent-color: #ff85a2;
        }
        
        body {
            display: flex;
            min-height: 100vh;
            flex-direction: column;
            background-color: #fff0f5;
        }
        main {
            flex: 1 0 auto;
            padding: 20px 0;
        }
        
        /* Enhanced Navigation Bar */
        nav {
            background: linear-gradient(135deg, var(--primary-color) 0%, var(--secondary-color) 100%);
            box-shadow: 0 2px 5px rgba(0,0,0,0.1);
            height: 64px;
            line-height: 64px;
        }
        
        .nav-wrapper {
            padding: 0 20px;
        }
        
        .brand-logo {
            display: flex;
            align-items: center;
            font-weight: 500;
            font-size: 1.5rem;
            padding-left: 10px;
        }
        
        .brand-logo i {
            margin-right: 10px;
            font-size: 1.8rem;
        }
        
        .nav-user-profile {
            display: flex;
            align-items: center;
            height: 64px;
            padding: 0 15px;
            cursor: pointer;
            transition: background-color 0.3s;
        }
        
        .nav-user-profile:hover {
            background-color: rgba(255,255,255,0.1);
        }
        
        .nav-user-profile img {
            margin-right: 10px;
        }
        
        .nav-user-profile .material-icons {
            margin-left: 5px;
        }
        
        /* Dropdown styling */
        .dropdown-content {
            border-radius: 0 0 10px 10px;
            background-color: white;
        }
        
        .dropdown-content li > a, 
        .dropdown-content li > span {
            color: var(--primary-dark);
            padding: 12px 16px;
        }
        
        .dropdown-content li > a:hover {
            background-color: #fff0f5;
        }
        
        .dropdown-content li.divider {
            background-color: #ffb6c1;
        }
        
        /* Rest of your existing CSS remains the same */
        .profile-pic {
            width: 40px;
            height: 40px;
            border-radius: 50%;
            object-fit: cover;
            margin-right: 10px;
        }
        .message-card {
            margin-bottom: 10px;
            cursor: pointer;
            transition: all 0.3s;
            border-radius: 10px;
        }
        .message-card:hover {
            box-shadow: 0 2px 10px rgba(255, 105, 180, 0.3);
        }
        .message-card.unread {
            border-left: 4px solid var(--primary-color);
        }
        .received {
            border-left: 4px solid var(--primary-color);
            background-color: rgba(255, 182, 193, 0.1);
        }
        .sent {
            border-left: 4px solid var(--secondary-color);
            background-color: rgba(255, 20, 147, 0.1);
        }
        .reply {
            border-left: 3px solid var(--accent-color);
        }
        .nav-wrapper {
    background: linear-gradient(135deg, #ff69b4 0%, #ff1493 100%);
    box-shadow: 0 2px 5px rgba(0,0,0,0.1);
}
        .user-avatar {
            width: 100px;
            height: 100px;
            border-radius: 50%;
            object-fit: cover;
            margin: 0 auto 20px;
            display: block;
            border: 3px solid var(--primary-light);
        }
        .btn, .btn-large {
            background-color: var(--primary-color);
        }
        .btn:hover, .btn-large:hover {
            background-color: var(--primary-dark);
        }
        .btn.secondary {
            background-color: var(--secondary-color);
        }
        .btn.secondary:hover {
            background-color: #c2185b;
        }
        .badge {
            margin-left: 10px;
        }
        .message-preview {
            color: #666;
            font-size: 0.9em;
        }
        .message-date {
            color: #999;
            font-size: 0.8em;
        }
        .modal {
            width: 80%;
            max-width: 600px;
            border-radius: 10px;
        }
        .modal-content {
            border-radius: 10px 10px 0 0;
        }
        .modal-footer {
            border-radius: 0 0 10px 10px;
        }
        #messageDetails {
            max-height: 300px;
            overflow-y: auto;
            padding: 10px;
            border: 1px solid #ffb6c1;
            border-radius: 4px;
            background-color: #fff5f9;
        }
        .chip {
            margin: 2px;
            background-color: var(--primary-light);
        }
        .user-details {
            font-size: 0.9em;
            color: #666;
            margin-top: 5px;
        }
        .user-details span {
            margin-right: 10px;
        }
        .search-container {
            margin-bottom: 20px;
            padding: 15px;
            background-color: white;
            border-radius: 10px;
            box-shadow: 0 2px 10px rgba(255, 105, 180, 0.1);
        }
        .search-loader {
            display: none;
            margin-left: 10px;
        }
        .search-results-info {
            font-size: 0.9em;
            color: #666;
            margin-bottom: 15px;
            padding: 10px;
            background-color: #ffe6ee;
            border-radius: 10px;
        }
        .search-highlight {
            background-color: #fff0f5;
            padding: 2px;
            border-radius: 2px;
        }
        .search-advanced {
            margin-top: 15px;
            padding-top: 15px;
            border-top: 1px solid #ffb6c1;
        }
        .search-toggle {
            cursor: pointer;
            color: var(--primary-color);
            font-size: 0.9em;
            margin-top: 10px;
            display: inline-block;
        }
        .hidden {
            display: none;
        }
        .card {
            border-radius: 10px;
            box-shadow: 0 2px 10px rgba(255, 105, 180, 0.1);
        }
        .card-panel {
            border-radius: 10px;
        }
        .dropdown-content {
            border-radius: 10px;
        }
        input:not([type]):focus:not([readonly]), 
        input[type=text]:not(.browser-default):focus:not([readonly]), 
        input[type=password]:not(.browser-default):focus:not([readonly]), 
        input[type=email]:not(.browser-default):focus:not([readonly]), 
        input[type=url]:not(.browser-default):focus:not([readonly]), 
        input[type=time]:not(.browser-default):focus:not([readonly]), 
        input[type=date]:not(.browser-default):focus:not([readonly]), 
        input[type=datetime]:not(.browser-default):focus:not([readonly]), 
        input[type=datetime-local]:not(.browser-default):focus:not([readonly]), 
        input[type=tel]:not(.browser-default):focus:not([readonly]), 
        input[type=number]:not(.browser-default):focus:not([readonly]), 
        input[type=search]:not(.browser-default):focus:not([readonly]), 
        textarea.materialize-textarea:focus:not([readonly]) {
            border-bottom: 1px solid var(--primary-color);
            box-shadow: 0 1px 0 0 var(--primary-color);
        }
        .input-field .prefix.active {
            color: var(--primary-color);
        }
        [type="checkbox"]:checked + span:not(.lever):before {
            border-right: 2px solid var(--primary-color);
            border-bottom: 2px solid var(--primary-color);
        }
        [type="radio"]:checked + span:after {
            background-color: var(--primary-color);
            border: 2px solid var(--primary-color);
        }
        .switch label input[type=checkbox]:checked + .lever {
            background-color: var(--primary-light);
        }
        .switch label input[type=checkbox]:checked + .lever:after {
            background-color: var(--primary-color);
        }
        .select-wrapper input.select-dropdown:focus {
            border-bottom: 1px solid var(--primary-color);
        }
        .dropdown-content li > a, .dropdown-content li > span {
            color: var(--primary-color);
        }


        /* Horizontal scrolling cards */
.user-cards-container {
    display: flex;
    overflow-x: auto;
    padding: 15px 0;
    margin-bottom: 20px;
    gap: 15px;
    scrollbar-width: thin;
    scrollbar-color: var(--primary-color) #f5f5f5;
}

.user-cards-container::-webkit-scrollbar {
    height: 8px;
}

.user-cards-container::-webkit-scrollbar-track {
    background: #f5f5f5;
    border-radius: 10px;
}

.user-cards-container::-webkit-scrollbar-thumb {
    background-color: var(--primary-color);
    border-radius: 10px;
}

.user-card {
    flex: 0 0 auto;
    width: 150px;
    border-radius: 10px;
    box-shadow: 0 2px 5px rgba(0,0,0,0.1);
    transition: transform 0.3s;
    background: white;
    cursor: pointer;
}

.user-card:hover {
    transform: translateY(-5px);
    box-shadow: 0 5px 15px rgba(255,105,180,0.3);
}

.user-card-image {
    width: 100%;
    height: 120px;
    object-fit: cover;
    border-radius: 10px 10px 0 0;
}

.user-card-content {
    padding: 10px;
    text-align: center;
}

.user-card-name {
    font-weight: 500;
    margin-bottom: 5px;
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
}

.user-card-age {
    color: var(--primary-dark);
    font-size: 0.9em;
}
.user-card-details {
    display: flex;
    justify-content: center;
    align-items: center;
    gap: 5px;
    font-size: 0.9em;
    color: var(--primary-dark);
}

.user-card-gender {
    color: #666;
}

/* Message User Modal Styles */
#messageUserModal .modal-content {
    padding: 24px;
    border-radius: 10px 10px 0 0;
}

#messageUserModal .modal-footer {
    border-radius: 0 0 10px 10px;
    padding: 10px 24px;
    background-color: #f5f5f5;
}

#messageUserModal .user-avatar {
    width: 80px;
    height: 80px;
    border-radius: 50%;
    object-fit: cover;
    border: 2px solid var(--primary-light);
}

#messageUserModal .input-field {
    margin-top: 20px;
}
    </style>
</head>
<body>
    <!-- Navigation -->
    <nav>
    <div class="nav-wrapper">
        <a href="?action=view_inbox" class="brand-logo">
            <i class="material-icons">favorite</i> Pink Dating
        </a>
        
        <ul class="right hide-on-med-and-down">
            <?php if (isLoggedIn()): ?>
                <li>
                    <a href="#" class="dropdown-trigger nav-user-profile" data-target="user-dropdown">
                        <img src="<?= getProfilePic($_SESSION['profile_pic']) ?>" class="profile-pic">
                        <?= htmlspecialchars($_SESSION['username']) ?>
                        <i class="material-icons right">arrow_drop_down</i>
                    </a>
                </li>
            <?php else: ?>
                <li><a href="?action=login" class="waves-effect waves-light hide-on-small-only">Login</a></li>
                <li><a href="?action=register" class="waves-effect waves-light hide-on-small-only">Register</a></li>
            <?php endif; ?>
        </ul>
        
        <!-- Mobile sidenav trigger - always show for mobile -->
        <a href="#" data-target="mobile-nav" class="sidenav-trigger right"><i class="material-icons">menu</i></a>
    </div>
</nav>

    
    <!-- Mobile Navigation -->
    <ul class="sidenav" id="mobile-nav">
    <?php if (isLoggedIn()): ?>
        <li>
            <div class="user-view">
                <div class="background" style="background: linear-gradient(135deg, var(--primary-color) 0%, var(--secondary-color) 100%);">
                </div>
                <a href="#">
                    <img class="circle" src="<?= getProfilePic($_SESSION['profile_pic']) ?>">
                </a>
                <a href="#">
                    <span class="white-text name"><?= htmlspecialchars($_SESSION['username']) ?></span>
                </a>
                <a href="#">
                    <span class="white-text email"><?= htmlspecialchars($_SESSION['email']) ?></span>
                </a>
            </div>
        </li>
        <li><a href="?action=view_inbox"><i class="material-icons">inbox</i> Inbox</a></li>
        <li><a href="?action=compose"><i class="material-icons">send</i> Compose</a></li>
        <li class="divider"></li>
        <li><a href="?action=logout"><i class="material-icons">exit_to_app</i> Logout</a></li>
    <?php else: ?>
        <li><a href="?action=login" class="waves-effect waves-light"><i class="material-icons">login</i> Login</a></li>
        <li><a href="?action=register" class="waves-effect waves-light"><i class="material-icons">person_add</i> Register</a></li>
    <?php endif; ?>
</ul>
    

    <!-- User dropdown -->
    <ul id="user-dropdown" class="dropdown-content">
        <li><a href="?action=view_inbox"><i class="material-icons">inbox</i> Inbox</a></li>
        <li><a href="?action=compose"><i class="material-icons">send</i> Compose</a></li>
        <li class="divider"></li>
        <li><a href="?action=logout"><i class="material-icons">exit_to_app</i> Logout</a></li>
    </ul>

    <!-- Reply Modal -->
    <div id="replyModal" class="modal">
        <div class="modal-content">
            <h4>Reply to Message</h4>
            <div id="messageDetails"></div>
            <form method="POST" action="?action=view_inbox">
                <input type="hidden" name="message_id" id="replyMessageId">
                <input type="hidden" name="reply_message" value="1">
                <div class="row">
                    <div class="input-field col s12">
                        <textarea id="reply_body" class="materialize-textarea" name="reply_body" required></textarea>
                        <label for="reply_body">Your reply</label>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="modal-close btn grey waves-effect waves-light">Cancel</button>
                    <button type="submit" class="btn waves-effect waves-light">Send Reply</button>
                </div>
            </form>
        </div>
    </div>

    <main class="container">
        <?php if ($error): ?>
            <div class="card-panel pink lighten-4 pink-text">
                <?= $error ?>
            </div>
        <?php endif; ?>
        
        <?php if ($success): ?>
            <div class="card-panel green lighten-4 green-text">
                <?= htmlspecialchars($success) ?>
            </div>
        <?php endif; ?>

        <?php if (!isLoggedIn()): ?>
            <!-- Auth Forms - Show by default for non-logged-in users -->
            <div class="row">
                <div class="col s12 m8 offset-m2">
                    <div class="card">
                        <div class="card-content">
                            <?php if ($action === 'login'): ?>
                                <span class="card-title">Login</span>
                                <form method="POST" action="?action=login">
                                    <div class="input-field">
                                        <i class="material-icons prefix">email</i>
                                        <input id="email" type="email" name="email" required>
                                        <label for="email">Email</label>
                                    </div>
                                    <div class="input-field">
                                        <i class="material-icons prefix">lock</i>
                                        <input id="password" type="password" name="password" required>
                                        <label for="password">Password</label>
                                    </div>
                                    <button class="btn waves-effect waves-light" type="submit">
                                        Login <i class="material-icons right">send</i>
                                    </button>
                                    <a href="?action=register" class="btn secondary waves-effect waves-light">
                                        Register
                                    </a>
                                </form>
                            <?php else: ?>
                                <span class="card-title">Create Your Profile</span>
                                <form method="POST" action="?action=register" enctype="multipart/form-data">
                                    <div class="row">
                                        <div class="input-field col s12 m6">
                                            <i class="material-icons prefix">person</i>
                                            <input id="username" type="text" name="username" required>
                                            <label for="username">Username</label>
                                        </div>
                                        <div class="input-field col s12 m6">
                                            <i class="material-icons prefix">email</i>
                                            <input id="email" type="email" name="email" required>
                                            <label for="email">Email</label>
                                        </div>
                                    </div>
                                    
                                    <div class="row">
                                        <div class="input-field col s12 m6">
                                            <i class="material-icons prefix">lock</i>
                                            <input id="password" type="password" name="password" required>
                                            <label for="password">Password</label>
                                        </div>
                                        <div class="input-field col s12 m6">
                                            <i class="material-icons prefix">lock_outline</i>
                                            <input id="confirm_password" type="password" name="confirm_password" required>
                                            <label for="confirm_password">Confirm Password</label>
                                        </div>
                                    </div>
                                    
                                    <div class="file-field input-field col s12">
                                        <div class="btn">
                                            <span>Profile Picture</span>
                                            <input type="file" name="profile_pic" accept="image/*">
                                        </div>
                                        <div class="file-path-wrapper">
                                            <input class="file-path" type="text" placeholder="Optional">
                                        </div>
                                    </div>
                                    
                                    <div class="row">
                                        <div class="input-field col s12 m4">
                                            <i class="material-icons prefix">straighten</i>
                                            <input id="height" type="number" name="height" step="0.1" min="3" max="8">
                                            <label for="height">Height (feet)</label>
                                        </div>
                                        <div class="input-field col s12 m4">
                                            <i class="material-icons prefix">cake</i>
                                            <input id="age" type="number" name="age" min="18" max="120">
                                            <label for="age">Age</label>
                                        </div>
                                        <div class="input-field col s12 m4">
                                            <i class="material-icons prefix">palette</i>
                                            <select name="skin_color">
                                                <option value="" disabled selected>Skin color</option>
                                                <option value="Light">Light</option>
                                                <option value="Medium">Medium</option>
                                                <option value="Dark">Dark</option>
                                                <option value="Other">Other</option>
                                            </select>
                                            <label>Skin Color</label>
                                        </div>
                                    </div>
                                    <div class="row">
                                        <div class="input-field col s12">
                                            <label>Gender</label>
                                            <select name="gender" required>
                                                <option value="" disabled selected></option>
                                                <option value="Male">Male</option>
                                                <option value="Female">Female</option>
                                                <option value="Non-binary">Non-binary</option>
                                                <option value="Other">Other</option>
                                            </select>
                                        </div>
                                    </div>
                                    
                                    <div class="row">
                                        <div class="col s12">
                                            <label>I'm interested in:</label>
                                            <div class="input-field">
                                                <select name="interests[]" multiple>
                                                <option value="reading">Reading</option>
                                                    <option value="writing">Writing</option>
                                                    <option value="painting">Painting</option>
                                                    <option value="drawing">Drawing</option>
                                                    <option value="photography">Photography</option>
                                                    <option value="hiking">Hiking</option>
                                                    <option value="camping">Camping</option>
                                                    <option value="swimming">Swimming</option>
                                                    <option value="running">Running</option>
                                                    <option value="cycling">Cycling</option>
                                                    <option value="gardening">Gardening</option>
                                                    <option value="cooking">Cooking</option>
                                                    <option value="baking">Baking</option>
                                                    <option value="playing_musical_instrument">Playing a Musical Instrument</option>
                                                    <option value="listening_to_music">Listening to Music</option>
                                                    <option value="watching_movies">Watching Movies</option>
                                                    <option value="playing_video_games">Playing Video Games</option>
                                                    <option value="board_games">Playing Board Games</option>
                                                    <option value="knitting">Knitting</option>
                                                    <option value="crocheting">Crocheting</option>
                                                    <option value="woodworking">Woodworking</option>
                                                    <option value="collecting">Collecting (e.g., stamps, coins)</option>
                                                    <option value="traveling">Traveling</option>
                                                    <option value="learning_languages">Learning Languages</option>
                                                    <option value="volunteering">Volunteering</option>
                                                    <option value="yoga">Yoga</option>
                                                    <option value="meditation">Meditation</option>
                                                    <option value="dancing">Dancing</option>
                                                    <option value="birdwatching">Birdwatching</option>
                                                    <option value="fishing">Fishing</option>
                                                    <option value="sports">Playing Sports (specify in another field)</option>
                                                    <option value="other">Other</option>
                                            </select>
                                            </div>
                                        </div>
                                    </div>
                                    
                                    <button class="btn waves-effect waves-light" type="submit">
                                        Register <i class="material-icons right">person_add</i>
                                    </button>
                                    <a href="?action=login" class="btn secondary waves-effect waves-light">
                                        Already have an account? Login
                                    </a>
                                </form>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            </div>
        
        <?php elseif (isLoggedIn()): ?>
            <!-- Main App Content -->
            <div class="row">
                <div class="col s12">

                <!-- Horizontal Cards -->

                <?php if (isLoggedIn()): ?>
                    <div class="user-cards-container">
                        <?php 
                        $allUsers = getUsers($_SESSION['user_id'], $pdo);
                        foreach ($allUsers as $user): 
                            $profilePic = getProfilePic($user['profile_pic']);
                        ?>
                            <div class="user-card" onclick="openUserModal(<?= $user['id'] ?>, '<?= htmlspecialchars(addslashes($user['username'])) ?>', '<?= getProfilePic($user['profile_pic']) ?>')">

                                <img src="<?= $profilePic ?>" alt="<?= htmlspecialchars($user['username']) ?>" class="user-card-image">
                                <div class="user-card-content">
                                    <div class="user-card-name"><?= htmlspecialchars($user['username']) ?></div>
                                    <div class="user-card-age"><?= $user['age'] ?? 'Age not set' ?></div>
                                    <span class="user-card-gender">• <?= $user['gender'] ?? '' ?></span>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>

                <!-- Inbox -->
                    <?php if ($action === 'view_inbox'): ?>
                        <!-- Unified Inbox View -->
                        <h4>Inbox</h4>
                        <?php $messages = getUnifiedInbox($_SESSION['user_id'], $pdo); ?>
                        
                        <?php if (empty($messages)): ?>
                            <div class="card-panel pink lighten-5">Your inbox is empty.</div>
                        <?php else: ?>
                            <?php foreach ($messages as $message): ?>
                                <div class="card message-card <?= $message['direction'] === 'received' ? 'received' : 'sent' ?>" 
                                     onclick="showMessageModal(<?= $message['id'] ?>)">
                                    <div class="card-content">
                                        <div class="row valign-wrapper" style="margin-bottom: 0;">
                                            <div class="col s1">
                                                <img src="<?= getProfilePic($message['direction'] === 'received' ? $message['sender_pic'] : $message['receiver_pic']) ?>" class="profile-pic">
                                            </div>
                                            <div class="col s11">
                                                <span class="card-title">
                                                    <?= htmlspecialchars($message['direction'] === 'received' ? $message['sender_username'] : $message['receiver_username']) ?>
                                                    <span class="badge <?= $message['direction'] === 'received' ? 'pink' : 'deep-pink' ?> white-text">
                                                        <?= $message['direction'] === 'received' ? 'Received' : 'Sent' ?>
                                                    </span>
                                                    <?php if ($message['type'] === 'reply'): ?>
                                                        <span class="badge pink lighten-2 white-text">Reply</span>
                                                    <?php endif; ?>
                                                    <?php if ($message['direction'] === 'received' && isset($message['is_read']) && !$message['is_read']): ?>
                                                        <span class="new badge pink" data-badge-caption="new"></span>
                                                    <?php endif; ?>
                                                </span>
                                                <p class="message-preview">
                                                    <?= substr(htmlspecialchars($message['body']), 0, 100) ?>...
                                                </p>
                                                <p class="message-date"><?= $message['created_at'] ?></p>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    
                    <?php elseif ($action === 'compose'): ?>
                        <!-- Search and Compose Message -->
                        <div class="search-container card">
                            <div class="card-content">
                                <h4>Find People to Message</h4>
                                <form method="POST" action="?action=search_users" id="searchForm">
                                    <div class="row">
                                        <div class="input-field col s12">
                                            <i class="material-icons prefix">search</i>
                                            <input id="search_term" type="text" name="search_term" value="<?= isset($_POST['search_term']) ? htmlspecialchars($_POST['search_term']) : '' ?>">
                                            <label for="search_term">Search by username, email, or interests</label>
                                        </div>
                                    </div>
                                    
                                    <div class="search-toggle" onclick="toggleAdvancedSearch()">
                                        <i class="material-icons tiny">expand_more</i> Advanced Search Options
                                    </div>
                                    
                                    <div id="advancedSearch" class="search-advanced hidden">
                                        <div class="row">
                                            <div class="input-field col s12 m4">
                                                <i class="material-icons prefix">cake</i>
                                                <input id="age" type="number" name="age" min="18" max="120" value="<?= isset($_POST['age']) ? htmlspecialchars($_POST['age']) : '' ?>">
                                                <label for="age">Age</label>
                                            </div>
                                            <div class="input-field col s12 m4">
                                                <i class="material-icons prefix">palette</i>
                                                <select name="skin_color">
                                                    <option value="" <?= empty($_POST['skin_color']) ? 'selected' : '' ?>>Any Skin Color</option>
                                                    <option value="Light" <?= isset($_POST['skin_color']) && $_POST['skin_color'] === 'Light' ? 'selected' : '' ?>>Light</option>
                                                    <option value="Medium" <?= isset($_POST['skin_color']) && $_POST['skin_color'] === 'Medium' ? 'selected' : '' ?>>Medium</option>
                                                    <option value="Dark" <?= isset($_POST['skin_color']) && $_POST['skin_color'] === 'Dark' ? 'selected' : '' ?>>Dark</option>
                                                    <option value="Other" <?= isset($_POST['skin_color']) && $_POST['skin_color'] === 'Other' ? 'selected' : '' ?>>Other</option>
                                                </select>
                                                <label>Skin Color</label>
                                            </div>
                                            <div class="input-field col s12 m4">
                                                <i class="material-icons prefix">favorite</i>
                                                <select name="interests[]" multiple>
                                                    <option value="Men" <?= isset($_POST['interests']) && in_array('Men', $_POST['interests']) ? 'selected' : '' ?>>Men</option>
                                                    <option value="Women" <?= isset($_POST['interests']) && in_array('Women', $_POST['interests']) ? 'selected' : '' ?>>Women</option>
                                                    <option value="Non-binary" <?= isset($_POST['interests']) && in_array('Non-binary', $_POST['interests']) ? 'selected' : '' ?>>Non-binary</option>
                                                    <option value="Transgender" <?= isset($_POST['interests']) && in_array('Transgender', $_POST['interests']) ? 'selected' : '' ?>>Transgender</option>
                                                    <option value="Other" <?= isset($_POST['interests']) && in_array('Other', $_POST['interests']) ? 'selected' : '' ?>>Other</option>
                                                </select>
                                                <label>Interests</label>
                                            </div>
                                        </div>
                                    </div>
                                    
                                    <div class="row">
                                        <div class="col s12">
                                            <button class="btn waves-effect waves-light" type="submit">
                                                Search <i class="material-icons right">search</i>
                                            </button>
                                            <a href="?action=compose" class="btn secondary waves-effect waves-light">
                                                Reset
                                            </a>
                                        </div>
                                    </div>
                                </form>
                                <div class="progress search-loader">
                                    <div class="indeterminate"></div>
                                </div>
                            </div>
                        </div>
                        
                        <?php if (isset($users)): ?>
                            <?php 
                            $searchTerm = $_POST['search_term'] ?? '';
                            $hasSearchParams = !empty($searchTerm) || !empty($_POST['age']) || !empty($_POST['skin_color']) || !empty($_POST['interests']);
                            ?>
                            
                            <?php if ($hasSearchParams): ?>
                                <div class="search-results-info">
                                    Showing <?= count($users) ?> result(s) for:
                                    <?php 
                                    $searchCriteria = [];
                                    if (!empty($searchTerm)) $searchCriteria[] = "search term: \"$searchTerm\"";
                                    if (!empty($_POST['age'])) $searchCriteria[] = "age: {$_POST['age']}";
                                    if (!empty($_POST['skin_color'])) $searchCriteria[] = "skin color: {$_POST['skin_color']}";
                                    if (!empty($_POST['interests'])) $searchCriteria[] = "interests: " . implode(', ', $_POST['interests']);
                                    echo implode(', ', $searchCriteria);
                                    ?>
                                </div>
                            <?php else: ?>
                                <div class="search-results-info">
                                    Showing all users that match your interests
                                </div>
                            <?php endif; ?>
                            
                            <form method="POST" action="?action=send_message">
                                <div class="input-field">
                                    <select name="receiver_id" required>
                                        <option value="" disabled selected>Choose recipient</option>
                                        <?php foreach ($users as $user): ?>
                                            <option value="<?= $user['id'] ?>" 
                                                    data-icon="<?= getProfilePic($user['profile_pic']) ?>"
                                                    data-height="<?= $user['height_feet'] ? 'Height: ' . $user['height_feet'] . 'ft' : '' ?>"
                                                    data-age="<?= $user['age'] ? 'Age: ' . $user['age'] : '' ?>"
                                                    data-skin="<?= $user['skin_color'] ? 'Skin: ' . $user['skin_color'] : '' ?>"
                                                    data-interests="<?= $user['interests'] ? 'Interests: ' . $user['interests'] : '' ?>">
                                                <?= htmlspecialchars($user['username']) ?> (<?= htmlspecialchars($user['email']) ?>)
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                    <label>Recipient</label>
                                    <div id="recipientDetails" class="user-details"></div>
                                </div>
                                <div class="input-field">
                                    <textarea id="body" class="materialize-textarea" name="body" required></textarea>
                                    <label for="body">Message</label>
                                </div>
                                <button class="btn waves-effect waves-light" type="submit">
                                    Send <i class="material-icons right">send</i>
                                </button>
                                <a href="?action=view_inbox" class="btn secondary waves-effect waves-light">
                                    Cancel
                                </a>
                            </form>
                        <?php endif; ?>
                    
                    <?php else: ?>
                        <!-- Default View (Inbox) -->
                        <?php header("Location: ?action=view_inbox"); exit(); ?>
                    <?php endif; ?>
                </div>
            </div>
        
        <?php else: ?>
            <!-- Redirect to login if not authenticated -->
            <?php header("Location: ?action=login"); exit(); ?>
        <?php endif; ?>
    </main>
 
    <!-- Message User Card Modal -->
<!-- Message User Modal -->
<!-- Message User Modal -->
<div id="messageUserModal" class="modal">
    <div class="modal-content">
        <h4>Message <span id="modalUserName"></span></h4>
        <div class="row">
            <div class="col s2">
                <img id="modalUserImage" src="" class="user-avatar">
            </div>
            <div class="col s10">
                <form id="messageUserForm" method="POST" action="?action=send_message">
                    <input type="hidden" name="receiver_id" id="modalUserId">
                    <div class="input-field">
                        <textarea id="modalMessageBody" class="materialize-textarea" name="body" required></textarea>
                        <label for="modalMessageBody">Your message</label>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="modal-close btn grey waves-effect waves-light">Cancel</button>
                        <button type="submit" class="btn pink waves-effect waves-light">Send Message</button>
                    </div>
                </form>
            </div>
        </div>
    </div>
</div>
    <!-- JavaScript -->
    <script src="https://cdnjs.cloudflare.com/ajax/libs/materialize/1.0.0/js/materialize.min.js"></script>
    <script>
        document.addEventListener('DOMContentLoaded', function() {
            // Initialize dropdown
            M.Dropdown.init(document.querySelectorAll('.dropdown-trigger'), {
                coverTrigger: false,
                constrainWidth: false,
                hover: false
            });

            // Initialize the message user modal when user card is clicked
            const messageModals = {
        replyModal: M.Modal.init(document.getElementById('replyModal')),
        messageUserModal: M.Modal.init(document.getElementById('messageUserModal'), {
            preventScrolling: true,
            dismissible: true,
            onCloseEnd: function() {
                document.getElementById('messageUserForm').reset();
            }
        })
    };


    window.openUserModal = function(userId, userName, userImage) {
        document.getElementById('modalUserName').textContent = userName;
        document.getElementById('modalUserImage').src = userImage;
        document.getElementById('modalUserId').value = userId;
        document.getElementById('modalMessageBody').value = '';
        
        messageModals.messageUserModal.open();
        
        setTimeout(() => {
            document.getElementById('modalMessageBody').focus();
        }, 100);

        window.closeUserModal = function() {
        modals.messageUserModal.close();
    };

    };


            // horizontal cards
            function openUserModal(userId, userName, userImage) {
                    // Set modal content
                    document.getElementById('modalUserName').textContent = userName;
                    document.getElementById('modalUserImage').src = userImage;
                    document.getElementById('modalUserId').value = userId;
                    
                    // Reset form
                    document.getElementById('modalMessageBody').value = '';
                    
                    // Open modal
                    const modal = M.Modal.getInstance(document.getElementById('messageUserModal'));
                    modal.open();
                    
                    // Focus textarea
                    setTimeout(() => {
                        document.getElementById('modalMessageBody').focus();
                    }, 100);
}

             // Initialize mobile sidenav
             M.Sidenav.init(document.querySelectorAll('.sidenav'));
            
            // Initialize select
            M.FormSelect.init(document.querySelectorAll('select'));
            
            // Initialize modal
            M.Modal.init(document.querySelectorAll('.modal'));
            
            // Update recipient details when selection changes
            const recipientSelect = document.querySelector('select[name="receiver_id"]');
            const recipientDetails = document.getElementById('recipientDetails');
            
            if (recipientSelect) {
                recipientSelect.addEventListener('change', function() {
                    const selectedOption = this.options[this.selectedIndex];
                    const details = [
                        selectedOption.dataset.height,
                        selectedOption.dataset.age,
                        selectedOption.dataset.skin,
                        selectedOption.dataset.interests
                    ].filter(Boolean).join(' | ');
                    
                    recipientDetails.innerHTML = details;
                });
            }
            
            // Auto-focus first input in forms
            const forms = document.querySelectorAll('form');
            forms.forEach(form => {
                const firstInput = form.querySelector('input, textarea, select');
                if (firstInput) firstInput.focus();
            });
            
            // Handle search form submission
            const searchForm = document.getElementById('searchForm');
            const searchLoader = document.querySelector('.search-loader');
            
            if (searchForm) {
                searchForm.addEventListener('submit', function() {
                    searchLoader.style.display = 'block';
                });
            }
            
            // Highlight search terms in results
            const searchTerm = "<?= isset($_POST['search_term']) ? addslashes($_POST['search_term']) : '' ?>";
            if (searchTerm) {
                const options = document.querySelectorAll('select[name="receiver_id"] option');
                options.forEach(option => {
                    if (option.textContent.includes(searchTerm)) {
                        const highlightedText = option.textContent.replace(
                            new RegExp(searchTerm, 'gi'), 
                            match => `<span class="search-highlight">${match}</span>`
                        );
                        option.innerHTML = highlightedText;
                    }
                });
            }
        });

        function toggleAdvancedSearch() {
            const advancedSearch = document.getElementById('advancedSearch');
            const toggleIcon = document.querySelector('.search-toggle i');
            
            if (advancedSearch.classList.contains('hidden')) {
                advancedSearch.classList.remove('hidden');
                toggleIcon.textContent = 'expand_less';
            } else {
                advancedSearch.classList.add('hidden');
                toggleIcon.textContent = 'expand_more';
            }
        }

        function showMessageModal(messageId) {
            // Fetch message details via AJAX
            fetch(`?action=get_message_details&message_id=${messageId}`)
                .then(response => {
                    if (!response.ok) {
                        throw new Error('Network response was not ok');
                    }
                    return response.json();
                })
                .then(message => {
                    // Format and display message details
                    const messageDetails = document.getElementById('messageDetails');
                    messageDetails.innerHTML = `
                        <div class="row">
                            <div class="col s2">
                                <img src="${message.sender_pic ? message.sender_pic : 'https://www.gravatar.com/avatar/00000000000000000000000000000000?d=mp&f=y'}" class="profile-pic">
                            </div>
                            <div class="col s10">
                                <p><strong>From:</strong> ${message.sender_username || message.sender_email}</p>
                                <p><strong>To:</strong> ${message.receiver_username || message.receiver_email}</p>
                                <p><strong>Date:</strong> ${message.created_at}</p>
                            </div>
                        </div>
                        <div class="divider"></div>
                        <div class="section">
                            <p>${message.body.replace(/\n/g, '<br>')}</p>
                        </div>
                    `;
                    
                    // Set the message ID for the reply form
                    document.getElementById('replyMessageId').value = message.id;
                    
                    // Open the modal
                    const modal = M.Modal.getInstance(document.getElementById('replyModal'));
                    modal.open();
                    
                    // Focus the reply textarea
                    document.getElementById('reply_body').focus();
                })
                .catch(error => {
                    console.error('Error fetching message details:', error);
                    M.toast({html: 'Error loading message details'});
                });
        }
    </script>

    <!-- Message User Modal -->
<div id="messageUserModal" class="modal">
    <div class="modal-content">
        <h4>Message <span id="modalUserName"></span></h4>
        <div class="row">
            <div class="col s2">
                <img id="modalUserImage" src="" class="user-avatar">
            </div>
            <div class="col s10">
                <form id="messageUserForm" method="POST" action="?action=send_message">
                    <input type="hidden" name="receiver_id" id="modalUserId">
                    <div class="input-field">
                        <textarea id="modalMessageBody" class="materialize-textarea" name="body" required></textarea>
                        <label for="modalMessageBody">Your message</label>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="modal-close btn grey waves-effect waves-light">Cancel</button>
                        <button type="submit" class="btn pink waves-effect waves-light">Send Message</button>
                    </div>
                </form>
            </div>
        </div>
    </div>
</div>
</body>
</html>