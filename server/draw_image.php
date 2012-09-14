<?php
// $Id: draw_image.php 122 2010-11-18 04:44:54Z s242720-studenti $

/*
 * Support routines to generate a pdf with the content
 * of the whiteboard.
 */
function draw_image($pages, $user_vars, $ext)
{
    $width  = $user_vars['svg_w'];
    $height = $user_vars['svg_h'];
    if ($ext == 'pdf') {
        $image = pdf_draw($pages, $width, $height);
        $header_string = 'Content-Type: application/pdf';
    } else {
	// Extension correctedness has already be checked into main.php
        // Page intervals can be exported just in the form of a pdf,
        // not in image form
        $page = array_pop($pages);
        $image = imagick_draw($page, $width, $height, $ext);
        // adjust jpg for the mimetype
        if ($ext == 'jpg')
            $ext = 'jpeg';
        $header_string = 'Content-Type: image/'.$ext;
    }
    return array($image, $header_string);
}

define('DEFAULT_WIDTH', 2);
define('FILL_DEFAULT_ALPHA', 0.5);
define('DEFAULT_ALPHA', 1);
define('DEFAULT_FONT_SIZE', 7);

function pdf_draw($pages, $width, $height)
{
    $pdf = new FPDF('P', 'pt', 'custom', $width, $height);
    $pdf->SetLineWidth(DEFAULT_WIDTH);
    // Don't change the font. Helvetica has been embedded into
    // fpdf.php files, and other font files were discarded (see
    // changes on top of fpdf.php file)
    $pdf->SetFont('Helvetica'); // XXX make configurable
    foreach ($pages as $objects) {
        $pdf->AddPage();
        $counter = 0;
        foreach($objects as $key => $object){
            $par = $object['par'];
            // Apply move actions if any: if this object is a multipath,
            // search move actions using the original id
            if (strpos($key, '_multipath_'))
                $objid = substr($key, 0, strpos($key, '_multipath_'));
            else
                $objid = $key;
            if (isset($objects[$objid]['move_x'])) {
                $mx = $objects[$objid]['move_x'];
                $my = $objects[$objid]['move_y'];
            } else {
                $mx = $my = 0;
	    }
            // parameters fixed for all shapes; the correspondance of other
            // parameters must be checked against those in shapes.js
            $stroke = hex2rgb($par[0]);
            $fill   = hex2rgb($par[1]);
            $par = array_slice($par, 2);
            switch ($object['typ']) {
            case 'path':
                list($opacity, $stroke_width, $d) = $par;
                $pdf->SetDrawColor($stroke['r'], $stroke['g'], $stroke['b']);
                $pdf->SetLineWidth($stroke_width);
                $pdf->SetAlpha($opacity);
                // There is an extra initial line between two identical
                // points, but this isn't a problem
                $x0 = $object['points'][0][0] + $mx;
                $y0 = $object['points'][0][1] + $my;
                foreach ($object['points'] as $coords) {
                    $x1 = $coords[0] + $mx;
                    $y1 = $coords[1] + $my;
                    $pdf->Line($x0, $y0, $x1, $y1);
                    $x0 = $x1;
                    $y0 = $y1;
                }
                break;

            case 'line':
                list($opacity, $stroke_width, $x1, $y1, $x2, $y2) = $par;
                $pdf->SetDrawColor($stroke['r'], $stroke['g'], $stroke['b']);
                $pdf->SetLineWidth($stroke_width);
                $pdf->SetAlpha($opacity);
                $pdf->Line($x1+$mx, $y1+$my, $x2+$mx, $y2+$my);
                break;

            case 'circle':
                list ($fill_opacity, $stroke_width, $cx, $cy, $r) = $par;
                // Draw stroke
                $pdf->SetDrawColor($stroke['r'],$stroke['g'],$stroke['b']);
                $pdf->Circle($cx+$mx,$cy+$my,$r, 'D');
                // Draw fill
                $pdf->SetAlpha($fill_opacity);
                $pdf->SetFillColor($fill['r'],$fill['g'],$fill['b']);
                $pdf->Circle($cx+$mx,$cy+$my,$r, 'F');
                break;

            case 'rect':
                list ($fill_opacity, $stroke_width, $x, $y, $width, $height) = $par;
                // Draw stroke
                $pdf->SetDrawColor($stroke['r'],$stroke['g'],$stroke['b']);
                $pdf->SetLineWidth($stroke_width);
                $pdf->rect($x+$mx,$y+$my,$width,$height,'D');
                // Draw fill
                $pdf->SetFillColor($fill['r'],$fill['g'],$fill['b']);
                $pdf->SetAlpha($fill_opacity);
                $pdf->rect($x+$mx,$y+$my,$width,$height,'F');
                break;

            case 'polygon':
                list($fill_opacity, $stroke_width, $pairs_str) = $par;
                $pdf->SetLineWidth($stroke_width);
                // Add an eventual 'move' action to points coordinates and
                // translate the points array into the form required by the
                // function interface (a flat array with 'x' on odd positions
                // and 'y' on even ones)
                $points = array();
                foreach($object['points'] as $point){
                    $points[] = (int)$point[0] + $mx;
                    $points[] = (int)$point[1] + $my;
                }
                // Draw stroke
                $pdf->SetDrawColor($stroke['r'],$stroke['g'],$stroke['b']);
                $pdf->polygon($points, 'D');
                // Draw fill
                $pdf->SetFillColor($fill['r'],$fill['g'],$fill['b']);
                $pdf->SetAlpha($fill_opacity);
                $pdf->polygon($points, 'F');
                break;

            case 'polyline':
                list($opacity, $stroke_width, ) = $par;
                $pdf->SetLineWidth($stroke_width);
                $pdf->SetAlpha($opacity);
                $pdf->SetDrawColor($stroke['r'],$stroke['g'],$stroke['b']);
                $points = $object['points'];
                $x0 = $points[0][0] + $mx;
                $y0 = $points[0][1] + $my;
                for($i = 1; $i < count($points); $i++){
                    $x1 = $points[$i][0] + $mx;
                    $y1 = $points[$i][1] + $my;
                    $pdf->Line($x0, $y0, $x1, $y1);
                    $x0 = $x1;
                    $y0 = $y1;
                }
                break;

            case 'text':
                list($x, $y, $content) = $par;
                $content = urldecode($content);
                $pdf->SetTextColor($fill['r'],$fill['g'],$fill['b']);
                //$pdf->SetFontSize($font_size);
                $rows = explode("\n", $content);
                foreach($rows as $row){
                    $pdf->Text($x+$mx, $y+$mx, $row);
                    //$y += $font_size;
                    $y += DEFAULT_FONT_SIZE;
                }
                break;

            case 'link':
                list($x, $y, $content) = $par;
                $content = urldecode($content);
                $pdf->SetTextColor(0,0,255);
                // This is the only case in which the current position is
                // used, so we don't need to restore original position after
                // writing the link
                $pdf->SetXY($x+$mx, $y+$my);
                //$pdf->Write(DEFAULT_FONT_SIZE, $content, $content);
                break;

            case 'image':
                list($x, $y, $content, $width, $height) = $par;
                // This function may fail if the GD library is not
                // present, or for some kind of images (for examples,
                // png with a depth of 16 bits). See the manual of
                // fpdf about the Image function
                try {
                    $pdf->Image(urldecode($content), $x+$mx, $y+$my,
				$width, $height);
                }
                catch(Exception $e) {
                    server_log('Failed to insert image ' . $content .
				': '.$e->getMessage());
                }
                break;
            }
            // Reset default values, in case they have changed
            $pdf->SetLineWidth(DEFAULT_WIDTH);
            $pdf->SetAlpha(DEFAULT_ALPHA);
        }
    }
    return $pdf->Output('', 'S');
}

// To convert from html notation to rgb values (used by pdf_draw)
function hex2rgb($hex)
{
    $color = str_replace('#','',$hex);
    $rgb = array('r' => hexdec(substr($color,0,2)),
                 'g' => hexdec(substr($color,2,2)),
                 'b' => hexdec(substr($color,4,2)));
    return $rgb;
}

function imagick_draw($objects, $width, $height, $ext)
{
    $draw = new ImagickDraw();
    foreach($objects as $key => $object){
        $par = $object['par'];
        // Apply move actions if any: if this object is a multipath,
        // search move actions using the original id
        if(strpos($key, '_multipath_'))
            $objid = substr($key, 0, strpos($key, '_multipath_'));
        else
            $objid = $key;
        if(isset($objects[$objid]['move_x'])){
            //(after the draw, we will come back to original draw
            // position)
            $draw->translate($objects[$objid]['move_x'],
                             $objects[$objid]['move_y']);
            $translated = true;
        }
        else
            $translated = false;
        $draw->setStrokeColor($par[0]);
        // Store the value of the fill color. For texts, this will be
        // set to stroke color
        $fill = $par[1];
        $draw->setFillColor($fill);
        $par = array_slice($par, 2);
        switch($object['typ']){
        case 'path':
            list($opacity, $stroke_width, $d) = $par;
            $draw->setStrokeWidth($stroke_width);
            $draw->setStrokeAlpha($opacity);
            $draw->setFillColor('none');
            $draw->pathStart();
            foreach($object['points'] as $coords){
                $draw->pathLineToAbsolute($coords[0], $coords[1]);
            }
            $draw->pathFinish();
            break;
        case 'line':
            list($opacity, $stroke_width, $x1, $y1, $x2, $y2) = $par;
            $draw->setStrokeWidth($stroke_width);
            $draw->setStrokeAlpha($opacity);
            $draw->line($x1, $y1, $x2, $y2);
            break;
        case 'circle':
            list ($fill_opacity, $stroke_width, $cx, $cy, $r) = $par;
            $draw->setStrokeWidth($stroke_width);
            $draw->setFillOpacity($fill_opacity);
            $draw->circle($cx, $cy, $cx+$r, $cy);
            break;
        case 'rect':
            list ($fill_opacity, $stroke_width, $x, $y, $w, $h) = $par;
            $draw->setStrokeWidth($stroke_width);
            $draw->setFillOpacity($fill_opacity);
            $draw->rectangle($x, $y, $x+$w, $y+$h);
            break;
        case 'polyline':
        case 'polygon':
            list($fill_opacity, $stroke_width, ) = $par;
            // Change the points array to adapt to the function interface
            // (change numerical indexes with 'x' and 'y' indexes for each
            // coordinate)
            $points = array();
            foreach($object['points'] as $point){
                $new_point = array('x'=>$point[0],
                                   'y'=>$point[1]);
                $points[] = $new_point;
            }
            $draw->setStrokeWidth($stroke_width);
            if($object['typ']=='polygon'){
                $draw->setFillOpacity($fill_opacity);
                $draw->polygon($points);
            }
            else{
                $draw->setFillColor('none');
                $draw->setStrokeAlpha($fill_opacity);
                $draw->polyline($points);
            }
            break;
        case 'text':
        case 'link':
            $draw->setStrokeWidth(0);
            // For Imagemagick, it is the Stroke color which
            // determines the text color, but for the SVG (and this
            // application) it is the fill color that matters
            if ($object['typ']=='link')
                $draw->setStrokeColor('blue');
            else
                $draw->setStrokeColor($fill);
            list($x, $y, $content) = $par;
            $content = urldecode($content);
            $draw->annotation($x, $y, $content);
            break;
        case 'image':
            list($x, $y, $content, $w, $h) = $par;
            // load the file for the contained image (this fopen checks
            // allow_url_fopen setting)
            $address = urldecode($content);
            try{
                $fp = fopen($address, 'r');
            }
            catch(Exception $e){
                // If an image can't be retrieved, simply skip that image
                server_log('Image '.$address.' could not be retrieved.');
                break;
            }
            $contained = new Imagick();
            $contained->readImageFile($fp);
            // insert into the draw, resizing too
            $draw->composite(imagick::COMPOSITE_DEFAULT, // Composite mode
                             $x, $y, $w, $h, $contained);
            break;
        }
        // If a translation has occurred, come back to the original
        // position
        if($translated)
            $draw->translate(-(int)$objects[$objid]['move_x'],
                             -(int)$objects[$objid]['move_y']);
        $draw->setStrokeWidth(DEFAULT_WIDTH);
        $draw->setStrokeAlpha(DEFAULT_ALPHA);
    }
    // The use of Imagick and ImagickDraw classes is taken from
    // imagick examples on php manual
    // http://it.php.net/manual/en/imagick.examples-1.php
    $image = new Imagick();
    $image->newImage($width, $height, 'white');
    $image->drawImage($draw);
    $image->setImageFormat($ext);
    return $image;
}

?>
