<?php
// Iniciar la sesion
session_start();

// Destruir todas las variables de sesion
$_SESSION = array();

// Destruir la sesion
session_destroy();

// Redirigir al login
header("Location: index.html");
exit();
?>