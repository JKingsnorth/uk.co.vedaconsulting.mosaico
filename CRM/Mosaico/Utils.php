<?php

require_once 'packages/mosaico/backend-php/premailer.php';

class CRM_Mosaico_Utils {

  static function getUrlMimeType($url) {
    $buffer = file_get_contents($url);
    $finfo = new finfo(FILEINFO_MIME_TYPE);
    return $finfo->buffer($buffer);
  }

  static function getConfig() {
    static $mConfig = array();

    if (empty($mConfig)) {
      $civiConfig = CRM_Core_Config::singleton();

      //DS FIXME: replace this with civi config
      $mConfig = array(
        /* base url for image folders */
        'BASE_URL' => $civiConfig->imageUploadURL,

        /* local file system base path to where image directories are located */
        'BASE_DIR' => $civiConfig->imageUploadDir,

        /* url to the static images folder (relative to BASE_URL) */
        'STATIC_URL' => "static/",

        /* local file system path to the static images folder (relative to BASE_DIR) */
        'STATIC_DIR' => "static/",

        /* url to the thumbnail images folder (relative to'BASE_URL'*/
        'THUMBNAILS_URL' => "uploads/thumbnails/",

        /* local file system path to the thumbnail images folder (relative to BASE_DIR) */
        'THUMBNAILS_DIR' => "uploads/thumbnails/",

        /* width and height of generated thumbnails */
        'THUMBNAIL_WIDTH' => 90,
        'THUMBNAIL_HEIGHT' => 90
      );
    }
    //CRM_Core_Error::debug_var('$mConfig', $mConfig);
    return $mConfig;
  }


  /**
   * handler for upload requests
   */
  static function processUpload()
  {
    $config = self::getConfig();

    global $http_return_code;

    $files = array();

    if ( $_SERVER[ "REQUEST_METHOD" ] == "GET" )
    {
      $dir = scandir( $config['BASE_DIR'] );

      foreach ( $dir as $file_name )
      {
        $file_path = $config['BASE_DIR'] . $file_name;

        if ( is_file( $file_path ) )
        {
          $size = filesize( $file_path );

          $file = [
            "name" => $file_name,
            "url" => $config['BASE_URL'] . $file_name,
            "size" => $size
          ];

          if ( file_exists( $config['BASE_DIR'] . $config[ THUMBNAILS_DIR ] . $file_name ) )
          {
            $file[ "thumbnailUrl" ] = $config['BASE_URL'] . $config[ THUMBNAILS_URL ] . $file_name;
          }

          $files[] = $file;
        }
      }
    }
    else if ( !empty( $_FILES ) )
    {
      foreach ( $_FILES[ "files" ][ "error" ] as $key => $error )
      {
        if ( $error == UPLOAD_ERR_OK )
        {
          $tmp_name = $_FILES[ "files" ][ "tmp_name" ][ $key ];

          $file_name = $_FILES[ "files" ][ "name" ][ $key ];

          $file_path = $config['BASE_DIR'] . $file_name;

          if ( move_uploaded_file( $tmp_name, $file_path ) === TRUE )
          {
            $size = filesize( $file_path );

            $image = new Imagick( $file_path );

            $image->resizeImage( $config[ THUMBNAIL_WIDTH ], $config[ THUMBNAIL_HEIGHT ], Imagick::FILTER_LANCZOS, 1.0, TRUE );
            // $image->writeImage( $config['BASE_DIR'] . $config[ THUMBNAILS_DIR ] . $file_name );
            if($f = fopen( $config['BASE_DIR'] . $config[ THUMBNAILS_DIR ] . $file_name, "w")){
              $image->writeImageFile($f);
            }            
            $image->destroy();

            $file = array(
              "name" => $file_name,
              "url" => $config['BASE_URL'] . $file_name,
              "size" => $size,
              "thumbnailUrl" => $config['BASE_URL'] . $config[ THUMBNAILS_URL ] . $file_name
            );

            $files[] = $file;
          }
          else
          {
            $http_return_code = 500;
            return;
          }
        }
        else
        {
          $http_return_code = 400;
          return;
        }
      }
    }

    header( "Content-Type: application/json; charset=utf-8" );
    header( "Connection: close" );

    echo json_encode( array( "files" => $files ) );
    CRM_Utils_System::civiExit();
  }

  /**
   * handler for img requests
   */
  static function processImg()
  {
    if ( $_SERVER[ "REQUEST_METHOD" ] == "GET" )
    {
      $method = $_GET[ "method" ];

      $params = explode( ",", $_GET[ "params" ] );

      $width = (int) $params[ 0 ];
      $height = (int) $params[ 1 ];

      if ( $method == "placeholder" )
      {
        $image = new Imagick();

        $image->newImage( $width, $height, "#707070" );
        $image->setImageFormat( "png" );

        $x = 0;
        $y = 0;
        $size = 40;

        $draw = new ImagickDraw();

        while ( $y < $height )
        {
          $draw->setFillColor( "#808080" );

          $points = [
            [ "x" => $x, "y" => $y ],
            [ "x" => $x + $size, "y" => $y ],
            [ "x" => $x + $size * 2, "y" => $y + $size ],
            [ "x" => $x + $size * 2, "y" => $y + $size * 2 ]
          ];

          $draw->polygon( $points );

          $points = [
            [ "x" => $x, "y" => $y + $size ],
            [ "x" => $x + $size, "y" => $y + $size * 2 ],
            [ "x" => $x, "y" => $y + $size * 2 ]
          ];

          $draw->polygon( $points );

          $x += $size * 2;

          if ( $x > $width )
          {
            $x = 0;
            $y += $size * 2;
          }
        }

        $draw->setFillColor( "#B0B0B0" );
        $draw->setFontSize( $width / 5 );
        $draw->setFontWeight( 800 );
        $draw->setGravity( Imagick::GRAVITY_CENTER );
        $draw->annotation( 0, 0, $width . " x " . $height );

        $image->drawImage( $draw );

        header( "Content-type: image/png" );

        echo $image;
      }
      else
      {
        $file_name = $_GET[ "src" ];

        $path_parts = pathinfo( $file_name );

        switch ( $path_parts[ "extension" ] )
        {
        case "png":
          $mime_type = "image/png";
          break;

        case "gif":
          $mime_type = "image/gif";
          break;

        default:
          $mime_type = "image/jpeg";
          break;
        }

        $file_name = $path_parts[ "basename" ];

        $image = self::resizeImage( $file_name, $method, $width, $height );

        header( "Content-type: " . $mime_type );

        echo $image;
      }
    }
    CRM_Utils_System::civiExit();
  }

  /**
   * handler for dl requests
   */
  static function processDl()
  {
    $config = self::getConfig();
    global $http_return_code;

    /* run this puppy through premailer */
    // DS: not sure why we need premailer as it always sends out mobile (inline) layout. 
    // Lets disable it till we figure out why we need it.
    //$premailer = Premailer::html( $_POST[ "html" ], true, "hpricot", $config['BASE_URL'] );
    //$html = $premailer[ "html" ];
    $html = $_POST[ "html" ];

    /* create static versions of resized images */

    $matches = [];

    $num_full_pattern_matches = preg_match_all( '#<img.*?src="([^"]*?\/[^/]*\.[^"]+)#i', $html, $matches );

    for ( $i = 0; $i < $num_full_pattern_matches; $i++ )
    {
      if (preg_match( '#/img/(\?|&amp;)src=#i', $matches[ 1 ][ $i ] ))
      {
        $src_matches = [];

        if ( preg_match( '#/img/(\?|&amp;)src=(.*)&amp;method=(.*)&amp;params=(.*)#i', $matches[ 1 ][ $i ], $src_matches ) !== FALSE )
        {
          $file_name = urldecode( $src_matches[ 2 ] );
          $file_name = substr( $file_name, strlen( $config['BASE_URL'] ) );

          $method = urldecode( $src_matches[ 3 ] );

          $params = urldecode( $src_matches[ 4 ] );
          $params = explode( ",", $params );
          $width = (int) $params[ 0 ];
          $height = (int) $params[ 1 ];

          $static_file_name = $method . "_" . $width . "x" . $height . "_" . $file_name;

          $html = str_ireplace( $matches[ 1 ][ $i ], $config['BASE_URL'] . $config['STATIC_URL'] . urlencode( $static_file_name ), $html );

          $image = self::resizeImage( $file_name, $method, $width, $height );

          $image->writeImage( $config['BASE_DIR'] . $config['STATIC_DIR'] . $static_file_name );
        }
      }
    }

    /* perform the requested action */

    switch (CRM_Utils_Type::escape($_POST['action'], 'String')) {
      case "download": {
        // download
        header( "Content-Type: application/force-download" );
        header( "Content-Disposition: attachment; filename=\"" . $_POST[ "filename" ] . "\"" );
        header( "Content-Length: " . strlen( $html ) );

        echo $html;
        break;
      }

      case "save": {
        $msgTplId = NULL;
        $hashKey  = CRM_Utils_Type::escape($_POST['key'], 'String');
        if (!$hashKey) {
          CRM_Core_Session::setStatus(ts('Mosaico hask key not found...'));
          return FALSE;
        }
        $mosTpl   = new CRM_Mosaico_DAO_MessageTemplate();
        $mosTpl->hash_key   = $hashKey;
        if($mosTpl->find(TRUE)){
          $msgTplId = $mosTpl->msg_tpl_id;
        }

        $name = "Mosaico Template " . date('d-m-Y H:i:s'); 
        if (CRM_Utils_Type::escape($_POST['name'], 'String')) {
          $name = $_POST['name'];
        }

        // save to message templates
        $messageTemplate = array(
          //'msg_text' => $formValues['text_message'],
          'msg_html'    => $html,
          'is_active'   => TRUE,
        );
        $messageTemplate['msg_title'] = $messageTemplate['msg_subject'];
        if ($msgTplId) {
          $messageTemplate['id'] = $msgTplId;
        }

        $messageTemplate['msg_title'] = $messageTemplate['msg_subject'] = $name;

        $msgTpl = CRM_Core_BAO_MessageTemplate::add($messageTemplate);
        $mosaicoTemplate = array(
          //'msg_text' => $formValues['text_message'],
          'msg_tpl_id' => $msgTpl->id,
          'hash_key'   => $hashKey,
          'name'       => $name,
          'html'       => $_POST['html'],
          'metadata'   => $_POST['metadata'],
          'template'   => $_POST['template'],
        );
        $mosTpl = new CRM_Mosaico_DAO_MessageTemplate();
        $mosTpl->msg_tpl_id = $msgTpl->id;
        $mosTpl->hash_key   = $hashKey;
        $mosTpl->find(TRUE);
        $mosTpl->copyValues($mosaicoTemplate);
        $mosTpl->save();

        break;
      }

      case "email": {
        if ( !CRM_Utils_Rule::email( $_POST['rcpt'] ) ) {
          CRM_Core_Session::setStatus('Recipient Email address not found');
          return FALSE;
        }
        $to      =  $_POST['rcpt'] ;
        $subject = CRM_Utils_Type::escape($_POST['subject'], 'String');
        list($domainEmailName, $domainEmailAddress) = CRM_Core_BAO_Domain::getNameAndEmail();
        $mailParams = array(
          //'groupName' => 'Activity Email Sender',
          'from'   => $domainEmailAddress, //FIXME: use configured from address
          'toName' => 'Test Recipient',
          'toEmail' => $to,
          'subject' => $subject,
          //'text' => $text_message,
          'html'   => $html,
        );

        if (!CRM_Utils_Mail::send($mailParams)) {
          return FALSE;
        }

        break;
      }
    }
    CRM_Utils_System::civiExit();
  }

  /**
   */
  static function getAllMetadata()
  {
    $result = array();
    $mosTpl = new CRM_Mosaico_DAO_MessageTemplate();
    $mosTpl->find();
    while ($mosTpl->fetch()) {
      CRM_Core_DAO::storeValues($mosTpl, $result[$mosTpl->hash_key]);
      unset($result[$mosTpl->hash_key]['html']);
    }
    CRM_Utils_JSON::output($result);
  }

  /**
   * function to resize images using resize or cover methods
   */
  static function resizeImage( $file_name, $method, $width, $height )
  {
    $config = self::getConfig();

    $image = new Imagick( $config['BASE_DIR'] . $file_name );

    if ( $method == "resize" )
    {
      $image->resizeImage( $width, $height, Imagick::FILTER_LANCZOS, 1.0 );
    }
    else // $method == "cover"
    {
      $image_geometry = $image->getImageGeometry();

      $width_ratio = $image_geometry[ "width" ] / $width;
      $height_ratio = $image_geometry[ "height" ] / $height;

      $resize_width = $width;
      $resize_height = $height;

      if ( $width_ratio > $height_ratio )
      {
        $resize_width = 0;
      }
      else
      {
        $resize_height = 0;
      }

      $image->resizeImage( $resize_width, $resize_height, Imagick::FILTER_LANCZOS, 1.0 );

      $image_geometry = $image->getImageGeometry();

      $x = ( $image_geometry[ "width" ] - $width ) / 2;
      $y = ( $image_geometry[ "height" ] - $height ) / 2;

      $image->cropImage( $width, $height, $x, $y );
    }

    return $image;
  }

}
