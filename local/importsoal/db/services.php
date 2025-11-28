<?php
$functions = array(
    'local_importsoal_create_question' => array(
        'classname'   => 'local_importsoal_external',
        'methodname'  => 'create_question',
        'classpath'   => 'local/importsoal/externallib.php',
        'description' => 'Create a question in a given category.',
        'type'        => 'write',
        'capabilities'=> ''
    ),

    'local_importsoal_add_question_to_quiz' => array(
        'classname'   => 'local_importsoal_external',
        'methodname'  => 'add_question_to_quiz',
        'classpath'   => 'local/importsoal/externallib.php',
        'description' => 'Add an existing question to a quiz.',
        'type'        => 'write'
    ),

    'local_importsoal_create_quiz' => array(
        'classname'   => 'local_importsoal_external',
        'methodname'  => 'create_quiz',
        'classpath'   => 'local/importsoal/externallib.php',
        'description' => 'Create quiz.',
        'type'        => 'write'
    ),

    'local_importsoal_get_categories' => array(
        'classname'   => 'local_importsoal_external',
        'methodname'  => 'get_categories',
        'classpath'   => 'local/importsoal/externallib.php',
        'description' => 'Get categories.',
        'type'        => 'read'
    ),
);

