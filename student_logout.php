<?php
require 'db.php';
require 'lib_session.php';
require 'lib_student_auth.php';

endStudentSession();
header('Location: index.php');
exit;
