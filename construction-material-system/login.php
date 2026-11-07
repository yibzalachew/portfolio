<?php
session_start();
require_once __DIR__ . "/includes/db.php";

if ($_SERVER["REQUEST_METHOD"] !== "POST") {
    header("Location: index.php");
    exit;
}

$username = trim($_POST["username"] ?? "");
$password = $_POST["password"] ?? "";

if ($username === "" || $password === "") {
    header("Location: index.php?error=required");
    exit;
}

$stmt = $pdo->prepare(
    "SELECT user_id, full_name, username, password_hash, role
     FROM users
     WHERE username = :username
     LIMIT 1"
);

$stmt->execute(["username" => $username]);
$user = $stmt->fetch();

if (!$user || !password_verify($password, $user["password_hash"])) {
    header("Location: index.php?error=invalid");
    exit;
}

session_regenerate_id(true);

$_SESSION["user_id"] = $user["user_id"];
$_SESSION["full_name"] = $user["full_name"];
$_SESSION["username"] = $user["username"];
$_SESSION["role"] = $user["role"];

header("Location: dashboard.php");
exit;
