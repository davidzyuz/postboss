<?php
/**
 * Plugin Name: Postboss
 * Description: The simpliest way to create blog posts
 * Version:     1.0.0
 * Author:      David Zyuz
*/

// script

// Изменение стандартной директории для загруженных файлов на 'wp-content/uploads/postboss'
function pboss_change_upload_dir( $dir ) {
    return array(
        'path'   => $dir['basedir'] . '/postboss',
        'url'    => $dir['baseurl'] . '/postboss',
        'subdir' => '/postboss',
    ) + $dir;
}

add_filter( 'upload_dir', 'pboss_change_upload_dir' );
// Изменение uploads/postboss на дефолт
function pboss_to_default_upload_dir() {
    remove_filter( 'upload_dir', 'pboss_change_upload_dir' );
}

//элемент меню pboss в боковой панеле администратора
add_action( 'admin_menu', 'pboss_options_page' );
function pboss_options_page() {
    add_menu_page(
        'Pboss',
        'Pboss',
        'manage_options',
        'pboss',
        'pboss_options_page_html'
    );
}

// Сердце pboss'a: загрузка и генерация постов происходит тут
function pboss_options_page_html() {
    if ( ! current_user_can( 'manage_options' ) ) {
        return;
    }

    add_filter( 'upload_dir', 'pboss_change_upload_dir' );

        ?>
            <div class="wrap">
                <h1>
                    Загрузите файл в формате CSV 
                    и Postboss всё сделает за Вас!</br>
                </h1>

                <form enctype="multipart/form-data" action="" method="POST">
                    <?php wp_nonce_field( 'upload_csv_file', 'fileup_nonce' ); ?>
                    <input name="upload_csv_file" type="file"/>
                    <input class="button button-primary" type="submit" value="Загрузить файл" />

                    <?php

                    //проверка файла и загрузка в папку 'wp-content/uploads/postboss'
                    if ( wp_verify_nonce( $_POST['fileup_nonce'], 'upload_csv_file' ) ) {

                        $upload_dir = wp_upload_dir()['path']  . '/*.csv';            
                        $files = glob( $upload_dir );

                        //проверка на существование файла с таким именем
                            if ( ! empty( $_POST ) ) {
                                $pattern = $_FILES["name"];
                            }
                    
                        for ( $i = 0; $i < count( $files ); $i += 1 ) {
                            if ( preg_match( $pattern, $files[$i] ) !== 0 ) {
                                return print_r( 'Файл с таким именем существует!' );
                            }
                        }

                        if ( ! function_exists( 'wp_handle_upload' ) ) {
                            require_once( ABSPATH . 'wp-admin/includes/file.php' );     
                        }

                        //проверка $_POST["'fileup_nonce"] и обработка файла в случае удачи
                        $file =& $_FILES['upload_csv_file'];
                        $overrides = ['test_form' => false];
                        $movefile = wp_handle_upload( $file, $overrides );
                        
                        if ( $movefile && empty( $movefile['error'] ) ) {
                            
                            pboss_create_cat_and_post('pboss_create_post');

                            echo "<p>
                                    Файл был успешно загружен и обработан!
                                </p>";
                        } else {
                            echo "<p>
                                    При загрузке файла произошла ошибка
                                </p>";
                        }
        
                    } 
                    
                    ?>
                </form>
            </div>

        <?php
        
}

// Функция, разбирает csv файл. Необязательный параметр = $nest_data
// Если вызвать с аргументом true результат будет в многомерном массиве
function pboss_parse_file_csv( $nest_data = false ) {

    $path_to_file = wp_upload_dir()['path'] . "/*.csv";
    $data = array();
    $files = glob( $path_to_file );

    foreach ($files as $file) {

        if ( $_file = fopen( $file, "r" ) ) {
            $post = array();
            $header = fgetcsv( $_file );

            while ( $row = fgetcsv( $_file ) ) {

                foreach ( $header as $i => $key ) {
                    $post[$key] = $row[$i];
                }
            }

            if ( ! $nest_data ) {
                $data = $post;
            } else {
                $data[] = $post;
            }
        }
        fclose( $file );
    }

    return $data;
}

//Проверка на наличие поста в базе данных
function pboss_post_exist( $title ) {

    global $wpdb;

    $posts = $wpdb->get_col( "SELECT post_title FROM {$wpdb->posts} WHERE post_type = 'post'" );

    return in_array( $title, $posts );
}

//Проверка на наличие категории в базе данных
function pboss_category_exist( $title ) {

    global $wpdb;

    $category = $wpdb->get_col( "SELECT name FROM {$wpdb->terms}");

    return in_array( $title, $category );
}

// Небольшой костыль для получения массива категорий из csv файла
function pboss_get_category_list ( $string, $delimeter = "|" ) {
    
    $result = array();
    $len = strlen( $string );
    
    for ($i = 0, $j = 0; $j < $len; $j++) {
        if ( $string[$j] === $delimeter ) {
            $i += 1;
            continue;
        }
      
        $result[$i] .= $string[$j];
    }

    return $result;
}

if (file_exists (ABSPATH.'/wp-admin/includes/taxonomy.php')) {
   require_once (ABSPATH.'/wp-admin/includes/taxonomy.php'); 
}

//pboss_parse_file_csv вызываем без аргументов, нет нужды в доп "глубине"    
function pboss_create_cat_and_post(string $callback) {

    $post = pboss_parse_file_csv();
    $cat_array = pboss_get_category_list( $post['Categories'] );
    $i = 0;
    $len = count( $cat_array);

    while ( $i < $len ) {

        if ( pboss_category_exist( $post['Categories'] ) ) {
            continue;
        }
        //Define the category
        $pboss_cat = array(
            'taxonomy' => 'category',
            'cat_name' => $cat_array[$i],
            'category_description' => $cat_array[$i],
            'category_nicename' => 'category-slug',
            'category_parent' => '');
 
        // Create the category
        $pboss_cat["id"] = wp_insert_category( $pboss_cat );

        $i += 1;
    }

    $callback();
}     


//в данном случае parse_csv вызывается с аргументом true
function pboss_create_post( ) {

    foreach( pboss_parse_file_csv(true) as $post ) {

        if (pboss_post_exist( $post["Title"] ) ) {
            continue;
        } 
        $insert_array = array(
            "post_date" => $post["Date"],
            "post_content" => $post["Content"],
            "post_title" => $post["Title"],
            "post_type" => "post",
            "post_status" => "publish"
        );

        $post["id"] = wp_insert_post( $insert_array);
    }
    
        //массив из категорий к которым привязан обрабатываемый пост
        $categories = pboss_get_category_list( $post['Categories'] );

        for( $i = 0; $i < count( $categories ); $i++ ) {
            $cat_id[$i] = get_cat_ID( $categories[$i] );
        }

        wp_set_post_terms( $post["id"], $cat_id, 'category', true );

        pboss_to_default_upload_dir();
        
}
