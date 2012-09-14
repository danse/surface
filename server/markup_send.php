<?php
// $Id: markup_send.php 133 2010-12-16 08:11:50Z s242720-studenti $

/*
 * Convert a php array to a xml file, following 2 levels of depth
 * (this means that a two-dimensional array gets correctly converted)
 */
function xml_send($object)
{
    // Prevent browsers from caching
    header('Expires: Fri, 25 Dec 1980 00:00:00 GMT'); // Time in the past
    header('Last-Modified: ' . gmdate( 'D, d M Y H:i:s') . 'GMT');
    header('Cache-Control: no-cache, must-revalidate');
    header('Pragma: no-cache');
    header('Content-Type: text/xml');

    $x = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>';
    $x .= '<response>';

    // The core conversion
    foreach($object as $key=>$value){
        if(gettype($value)=='array')
            foreach($value as $key2=>$value2)
                $x .= '<'.$key2.'>'.$value2.'</'.$key2.'>';
        else
            $x .= '<'.$key.'>'.$value.'</'.$key.'>';
    }

    $x .= '</response>';
    echo $x;
}

/* HTML multiline strings are started with a newline that sets the
 * indentation of the new block, so each block coming from a script
 * will hopefully mantain the same indentation, making it easier to
 * identify its origin */

/* There are two functions called from the outside to write html:
 * "login_page_send" and "app_page_send". Both of them use the
 * common function "common_frame". */

/*
 * Create the login page
 */
function login_page_send($salt, $msg='')
{
    // Find existing sessions on the filesystem and make a radio input
    // with them
    global $wb_dir, $client_ajax_timeout;
    $found_sessions = '';
    $first = true;
    $h = opendir($wb_dir);
    if($h)
        while(($session=readdir($h))!==false )
            // Don't add debug files, lock files and hidden files. If
            // the file is valid, add a radio input with that name.
            // The range of valid names read here is coupled with the
            // client-side substitutions which remove the points and
            // the other ignored keywords (function session_create
            // file login.js)
            if((strpos($session, '-debug')===false) &&
               (strpos($session, '-lock')===false) &&
               (strpos($session, '.')===false)){
                $found_sessions .= '
      <input type="radio" name="wb_id" value="'.$session.'"';
                // The first entry must be "checked"
                if ($first){
                    $found_sessions .= ' checked="checked" ';
                    $first = false;
                }
                $found_sessions .= '>'.$session.'<br>';
            }
    closedir($h);

    $content = '
  <div class="login">
   <form method="post" id="login_form" >
    <table>
     <tr>
      <td>Choose session:</td>
      <td>'.$found_sessions.'</td>
      <td>
        <input name="createsession" type="button" value="Create new"
               onclick="session_create()" disabled="disabled">
      </td>
     </tr>
     <tr>
      <td>Name:</td>
      <td>
       <input name="user" type="text"
              onkeyup="g[\'login\'].validate(this.name)">
      </td>
      <td>
      </td>
     </tr>
     <tr>
      <td>Password:</td>
      <td>
       <input name="pass" type="password" onkeyup="g[\'login\'].validate(this.name);">
      </td>
      <td>
       <input name="submit" type="button" value="Register" disabled="disabled"
              onclick="g[\'login\'].submit()">
      </td>
     </tr>
    </table>
     <p id="message_space"></p>
     <input name="salt" type="hidden" value="'.$salt.'">
     <input name="ajax_timeout" type="hidden" value="'.$client_ajax_timeout.'">
     <input name="initial_message" type="hidden" value="'.$msg.'">
   </form>
  </div>';
    echo common_frame('Login', $content);
}

/*
 * Create the whiteboard page
 */
function app_page_send($client_vars, $msg='')
{
    // layout parameters
    global $layout;
    // retrieve the whiteboard name (parse_client_id is defined into
    // updates.php)
    $session = parse_client_id($client_vars['client_id'], 'wb');

    // Content width and height (taking away the space for container's
    // padding and margin and for the name panel)
    $content_w = $client_vars['width'] - $layout['frame_narrow_width'];
    $content_h = $client_vars['height'] - $layout['frame_narrow_height'];

    $svg_w = $content_w - $client_vars['side_w'] - $layout['margin'] - $layout['m_w'];
    $svg_h = $content_h;

    $content = '
             <!-- this div is just to avoid explorer\'s peekaboo bug,
             and the width is useful just to give layout to the div to
             avoid the bug -->
             <div>
               <div id="style_div" style="float:right"></div>
               <div id="menu_bar"> '.menu_nodes().' </div>
             </div>
             <div style="clear:both"><hr/></div>
             <div id="left_column" style="display:inline; float:left;">
               '.whiteboard_nodes($svg_w, $svg_h, $layout['m_w']).'
             </div>
             <div id="vertical_separator" onclick="inner_resize()"
                  style="height:'.$content_h.'px">
               &nbsp;
             </div>
             <div id="right_column" style="width: '.$client_vars['side_w'].'px; display: inline; float: left;">
               <div id="right_top" style="height:'.$content_h.'px">
                 '.chat_nodes($content_h, $layout['margin']).'
               </div>
               <div id="horizontal_separator" onclick="user_right_resize()"></div>
               <div id="right_bottom">
                 <!-- Here the javascript could insert an iframe -->
               </div>
             </div>
             <div id="notify_area" style="clear: both; height:30px; width:'.$content_w.'px">
               Logged as user <b>'.$client_vars['user'].'</b> for session <b>'.$session.'</b>
               <br>
               <div id="log_space">'.htmlentities($msg).'</div>
             </div>
             <!-- Client side session variables read by init() into common.js -->
             <div class="hidden">
               <form id="session_data">';
    foreach ($client_vars as $name=>$value)
        $content .= '
                 <input type="hidden" name="'.$name.'" value="'.$value.'">';
    $content .= '
               </form>
             </div>';
    echo common_frame('Whiteboard', $content);
}

/* Write an html frame which is mostly common to both pages, but if
 * $name is 'Whiteboard', different .js files will be included into
 * the head */

function common_frame($name, $content){
    // The php header is defined here because this function is called
    // for every written html page
    header('Content-type: text/html');
    $document = '
<!DOCTYPE html PUBLIC "-//W3C//DTD HTML 4.01//EN" "http://www.w3.org/TR/html4/strict.dtd">
<html>
 <head>
  <title>Web Whiteboard</title>
  <meta http-equiv="Content-Type" content="text/html; charset=UTF-8">

  <link href="style/common.css" rel="stylesheet" type="text/css" >';
    if($name == 'Whiteboard')
        $document .= '
  <link href="style/application.css" rel="stylesheet" type="text/css">';

    $document .= '

  <!-- Inclusion order affects variables visibility. Into
  \'common.js\' the global object "g" is defined -->
  <script type="text/javascript" src="client/common-pack.js"></script>';

    if($name == 'Whiteboard')
        $document .= '
  <!-- This meta tag must go before svg.js inclusion -->
  <meta name="svg.render.forceflash" content="false">
  <script type="text/javascript" src="client/svg.js" data-path="client/"></script>
  <script type="text/javascript" src="client/application-pack.js"></script>';
    else
        $document .= '
  <script type="text/javascript" src="client/login.js"></script>';
    $document .= '
 </head>
 <body>
  <noscript><p>This application requires JavaScript</p></noscript>';
    if($name == 'Whiteboard')
        $document .= '<img src="images/resize.png" alt="Resize"
                       onclick="frame_resize()">';
    else
        // A placeholder for the image, to make the login page look like
        // the app page
        $document .='<div style="width:20px; height:21px;">&nbsp;</div>';
    $document .= '
  <!-- the table\'s purpose is just centering while fitting its
  contents (divs can be centered only when their width is fixed) -->
  <table style="margin: auto">
<tbody>
    <tr>
      <td>
        <div id="container_div" class="container_div_s_default">
          <div id="name_panel">
            <div id="help_div" style="display:inline; float:right">
              <a href="user_guide.html" style="text-decoration:none" target="_blank">
                help
              </a>
            </div>
            '.$name.'
          </div>
          '.$content.'
        </div>
      </td>
    </tr>
</tbody>
  </table>
 </body>
</html>';
    return $document;
}

// Almost all variables of control_nodes function are server side
// variables which we want to give to the client side, stored as
// hidden input fields.
function menu_nodes(){
    $result = '
      <div id="control_default">';
    if (extension_loaded('imagick'))
        $result .= '
        <button onclick="show_div(\'menu_import\', true)">Import</button>';
    $result .= '
        <button onclick="show_div(\'menu_export\', true)">Export</button>
        <button onclick="show_div(\'menu_slides\', true)">Slides</button>
        <button onclick="del_ses()">Delete session</button>
        <button onclick="refresh()">Refresh</button>
        <button onclick="logout()">Logout</button>
      </div>
      <!-- Hidden control forms (hidden inputs will be added to some
           of them by the function show_form) -->
      <div id="menu_import" style="display:none">
        <form id="import_form" action="index.php"
              method="post" enctype="multipart/form-data">
          Choose a file to import (a <b>pdf</b> file or a <b>gif, png, jpg</b> image)
          <input name="file" type="file" size="20">
          <input name="mode" type="hidden" value="import">
          <input type="button" value="Import" onclick="import_submit()">
        </form>
        <!-- the following hidden iframe is used to upload the file in
        an asynchronous way (see javascript function import_submit)
        -->
        <iframe id="import_target_frame" name="import_target_frame" style="display:none"></iframe>
        <button onclick="show_div(\'menu_import\', false)">Cancel</button>
      </div>
      <div id="menu_export" style="display:none">
        <form id="export_form">
          Which page do you want to export?
          <div class="inline">
            <select name= "exp_mode" onchange="export_mode_change(event)">
              <!-- explorer requires explicit values -->
              <option value="current">current</option>
              <option value="all">all</option>
              <option value="interval">interval</option>
              <option value="chat">chat</option>
            </select>
          </div>
          <div id="export_pages" class="hidden">
            <input type="text" size="3" name="exp_from" value="0">
            <input type="text" size="3" name="exp_to" value="10">
          </div>
          <div id="export_extension_choice" class="inline">
            Choose the file format:&nbsp;
            <select name="export_extension">
              <option value="pdf">pdf</option>';
        if (extension_loaded('imagick'))
            $result .= '
              <option value="png">png</option>
              <option value="jpg">jpg</option>
              <option value="gif">gif</option>';
        $result .= '
            </select>
          </div>
          <input type="button" value="Export" name="export_submit_button"
                 onclick="export_submit()">
        </form>
        <button onclick="show_div(\'menu_export\', false)">Cancel</button>
      </div>
      <div id="menu_slides" style="display:none">
        <form id="slides_form">
          Insert the address for the slides:
          <input type="text" size="20" name="slides_address">
          <input type="button" value="Load slides" onclick="load_slides()">
        </form>
        <button onclick="show_div(\'menu_slides\', false)">Cancel</button>
        &nbsp;&nbsp;&nbsp;
        <button onclick="hide_slides()">Hide slides</button>
      </div>';
    return $result;
}

// The parameters are inner (extern minus padding) height and margin
function chat_nodes($h, $margin){
    // Subtract the horizontal resize panel and the two 1px borders
    $h = $h - $margin - 2;
    $out_h = $h * 0.8;
    $inp_h = $h * 0.2 - $margin;
    return '
      <div id="text_output" style="width: 100%; height:'.$out_h.'px"></div>
      <!-- this is just to separate the other two elements -->
      <div style="height:'.$margin.'px;"></div>
      <textarea id="text_input_t"
                onkeyup="chatOnKeyUp(event)"
                style="width: 100%; height:'.$inp_h.'px"></textarea>';
}

// Generate the svg file for the whiteboard
// w, h: whiteboard width and height
// m_w: menu width
// margin: margin size
// svg_w, svg_h: resulting svg width and height
function whiteboard_nodes($svg_w, $svg_h, $m_w){
    return '
      <div id="svg_container">
       <script type="image/svg+xml">
        <svg id="svg_root" width="'.($svg_w+1).'" height="'.($svg_h+1).'"
          xmlns="http://www.w3.org/2000/svg"
          xmlns:xlink="http://www.w3.org/1999/xlink">
          <!--clipPath id="clip_rect"><rect height="100px" width="100px" y="10px" x="10px"></rect></clipPath-->
         <!-- the position of this element (before the group that
          will keep all the shapes) is important to render it
          behind all other shapes -->
         <rect id="canvas" fill="white" width="'.$svg_w.'" height="'.$svg_h.'" />
         <g id="whiteboard_g"/>
        </svg>
       </script>
      </div>
      <div style="width: 10px; float:left">&nbsp</div>
      <div id="toolbox" style="width:'.$m_w.'px">'.
        create_palette("Fill").
        create_palette("Stroke").
        create_toolbox().'
      </div>
      <textarea id="textinput" onkeyup="whiteboardOnKeyUp(event)" '.
       'rows="5" cols="20" style="display:none"></textarea>';
}

// Generate a color palette. Each color sample is assigned to a class,
// so for each color code into the 'colors' array there exists a
// corresponding class into whiteboard.css. The color code will be
// retrieved by client side javascript to assign it to svg elements. I
// use this weird class structure for problems on client side with
// explorer: target.style isn't supported well as target.className by
// explorer, so to change the sample square appearence I need to use
// class names
function create_palette($type){
    $colors = array('000000', 'FFFFFF', 'A020F0', '0000CD',
                    '00FFFF', '49E20E', 'FF0000', 'FF8000',
                    'EEEE00', 'FF1CAE');
    $text = $type.':';
    $handler = 'handle'.$type;
    $id = strtolower($type);
    // Many of the following divs exist to do css styling
    $result = '
       <div class="color_table">
        <div class="color_selected">
         '.$text.'
         <div id="'.$id.'_sample" class="color_sample color_default">
         </div>
        </div>
        <div id="'.$id.'_palette" class="color_palette">';
    for($i=0; $i<count($colors); $i++)
        $result .= '<div class="color_sample color_'.$colors[$i].'" '.
            'onclick="'.$handler.'(event)">'.
            '</div>';
    $result .= '
        </div> 
       </div>';
    return $result;
}

function create_toolbox()
{
    $buttons = array('Path', 'Line', 'Polyline', 'Polygon', 'Rect',
                     'Circle', 'Text', 'Image', 'Link', 'Move', 'Edit',
                     'Delete', 'Select', 'Clear');
    $result = '
       <div id="buttons">';
    // Add buttons updating height
    foreach($buttons as $i => $value)
        $result .= '
        <input type="button" id="'.strtolower($value).'_button" '.
               'value="'.$value.'" '.
               'onclick="handleTool(event)" '.
               'class="draw_button"><br>';
    // Last three buttons have a different class. The page input
    // has an'id' to be accessed by the javascript
    $result .= '
        <input class="draw_button_little" type="button" value="&lt;" '.
               'onclick="handleTool(event)" >
        <input class="draw_button_little" type="text" value="0" '.
               'id="page_input" onkeyup ="handlePageInput(event)" >
        <input class="draw_button_little" type="button" value="&gt;" '.
               'onclick="handleTool(event)" >';
    // This hidden divs contain additional commands for different tools;
    // their name is binded client side with the values given to
    // variable additional_panel into function handleTool
    $result .='
        <div id="open_shape_style" class="hidden">
          Stroke width:<br>
          <input name="open_stroke" type="text" class="draw_button"
                 onkeyup="set_shape_attribute(\'stroke-width\', event, /^\d+$/)"
                 value="2">
          <br>
          Stroke opacity:<br>
          <input name="open_opacity" type="text" class="draw_button"
                 onkeyup="set_shape_attribute(\'opacity\', event, /(^1$)|(^0$)|(^0?\.\d+$)/)"
                 value="1">
          <br>
        </div>
        <div id="closed_shape_style" class="hidden">
          <!-- the name of these input fields match actual svg attributes -->
          Stroke width:<br>
          <input name="closed_stroke" type="text" class="draw_button"
                 onkeyup="set_shape_attribute(\'stroke-width\', event, /^\d+$/)" value="2"
                 value="5">
          <br>
          <!-- Stroke opacity isn\'t supported by firefox\'s 3.6.10 native renderer -->
          Fill opacity:<br>
          <input name="closed_fill_opacity" type="text" class="draw_button"
                 onkeyup="set_shape_attribute(\'fill-opacity\', event, /(^1$)|(^0$)|(^0?\.\d+$)/)"
                 value="0.0">
          <br>
        </div>
        <div id="image_style" class="hidden">
          Image scaling factor:<br>
          <input name="image_scaling" type="text" class="draw_button"
                 onkeyup="set_shape_attribute(\'scaling-factor\', event, /^\d+$/)"
                 value="100">
          <br>
        </div>
       </div>';
    return $result;
}

?>
