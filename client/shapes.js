// $Id: shapes.js 132 2010-12-13 10:53:17Z s242720-studenti $

g['shape_generator'] = {
    // default values (some of these values are ignored by some
    // shapes, depending on each shape's attribute list). These can be
    // changed by the user.
    default_values: {
        'stroke': '#000000',
        'fill': '#000000',
        'stroke-width': 5,
        'opacity': 1,
        'fill-opacity': 0.0,
        // unlike the others, scaling-factor is not an SVG attribute
        'scaling-factor': 100
    },
    generate_shape: function(string, page){
        var shape;
        if(string=='move'||
           string=='edit'||
           string=='delete'||
           string=='select'||
           string=='clear'||
           string=='refresh')
            shape = not_active_shape();
        else{
            switch(string){
            case 'line'      : shape = line()              ; break;
            case 'rect'      : shape = rect()              ; break;
            case 'circle'    : shape = circle()            ; break;
            case 'path'      : shape = path()              ; break;
            case 'multipath' : shape = path('multipath')   ; break;
            case 'polygon'   : shape = polygon()           ; break;
            case 'polyline'  : shape = polygon('polyline') ; break;
            case 'text'      : shape = text()              ; break;
            case 'link'      : shape = link()              ; break;
            case 'image'     : shape = image()             ; break;
            }
            // If generate_shape is called with a page value (a server
            // update), set the page. Otherwise (an user tool
            // activation), the page will be set when the shape is
            // started the first time (open_shape)
            if(page != undefined)
                shape.page = page;
            // Assign default values
            for (v in this.default_values)
                shape[v] = this.default_values[v];
        }
        return shape;
    }
}

/* base class from which all the shapes are derived */
function shape(type, att){
    var object = {};
    object.type = type;

    // The colors are common attributes between all shapes
    var colors = ['stroke', 'fill'];
    object.att = colors.concat(att);

    object.open = false;
    object.edit = false;
    // active is used by the 'edit' tool. An edited shape remains on
    // the global scope, but it is unactivated, so the upstream code
    // understands that it can edit a new shape.
    object.active = true;

    object.open_shape = function(){
        this.page = g['pages'].current;
        this.open = true;
    };
    object.close_shape = function(){
        this.open = false;
        // If this was an edit shape, make it unusable to allow to
        // click on a new shape
        if (this.edit)
            this.active = false;
    };
    // create_group sets the 'id' and 'group' attributes of the shape
    // object. If an 'id' is given to create_group, the group is
    // already existent, otherwise, a new id will be generated.
    object.create_group = function(id){
        if(id)
            this.id = id;
        else
            // Generate unique object id
            this.id = g['idObj'].get_new();

        this.group = document.getElementById(id)
        if(!this.group){
            // Create the <g> element
            this.group = document.createElementNS(svgns, 'g');
            this.group.setAttribute('id', this.id);
            // Append the group to the page assigned by the
            // page_generator
            g['pages'].append(this.group, this.page);
        }
    };
    // Automatically create an SVG element, when its type and
    // attributes are like the shape type and attributes
    object.create_element = function(){
        this.element = document.createElementNS(svgns, this.type);
        for(a in this.att)
            this.element.setAttribute(this.att[a], this[this.att[a]]);
        this.group.appendChild(this.element);
    };
    // start_shape, end_shape and server_create are built using the
    // functions above. The most of the times the shapes will use
    // start_shape, end_shape, and server_create, but sometimes a
    // finer control is useful
    object.start_shape = function(id){
        this.open_shape();
        this.create_group(id);
        this.create_element();
    };
    object.end_shape = function(){
        var params = [];
        for(a in this.att)
            params.push(this.element.getAttribute(this.att[a]));
        // Add the element in the array that will be sent to the server.
        sender_add(this.type, params, this.id);
        this.close_shape();
    };
    object.server_create = function(par, id){
        for(a in this.att)
            this[this.att[a]] = par[a];
        this.create_group(id);
        this.create_element();
    };

    /* Retrieve attributes from the edited object */
    object.copy_shape = function(target){
        for(a in this.att)
            this[this.att[a]] = target.getAttribute(this.att[a]);

        // Retrieve the translation values applied to the object (by
        // move actions). These values can be added to the attributes
        // read from the old shape.
        var parentNode = target.parentNode;
        this.offset_x = Number(parentNode.getCTM()['e']);
        this.offset_y = Number(parentNode.getCTM()['f']);

        this.edit = true;
    };

    /* following functions (show_text_area, onkeyup) will be used just
     * by the shapes: text, link, image, which require textual input
     * from the user. After receiving the textual input, shapes are
     * created as if the data came from the server. Each of these
     * classes must define a 'text' attribute to receive the user
     * input */
    object.show_text_area = function(x, y){
        // close_shape is at the end of onkeyup
        this.open_shape();
        // Set the coordinates of the textarea and display it (or just
        // move it to this point if it is visible already)
        var text_area = getById('textinput');
        text_area.style.left = x + g['x_offset'] + 'px';
        text_area.style.top  = y + g['y_offset'] + 'px';
        text_area.style.display = 'inline';
        text_area.focus();
    };
    object.onkeyup = function(event){
        key = cross_which(event);
        // This function should continue only if the user pressed just
        // "enter" (and not "shift+enter")
        if (key == 13 && !cross_shift(event)){
            var textarea = getById("textinput");
            // Hide the textarea and get her value
            textarea.style.display = 'none';
            var text = textarea.value;
     
            // Get rid of all whitespaces and newlines before and after the text
            text = text.replace(/(^[ \n\r]+)|([ \n\r]+$)/g, '');
            if (text != ''){
                // Escape the text for separators like :, |, etcetera. It must
                // be unescaped client side by server_create functions, and
                // server side where needed. The escape is done two times,
                // because it is unescaped once by the server while reading the
                // POST ajax request, whose mime type is
                // "application/www-form-urlencoded"
                this.text = escape(escape(text));
                if (this.type === 'image'){
                    // Each new image update sent to the server carries
                    // the scale factor for the image into the 'stroke'
                    // slot of the update (which would be unused
                    // otherwise)
                    this['stroke'] = this['scaling-factor'];
                }
                // Build the parameter array
                var par = [];
                for(a in this.att)
                    par.push(this[this.att[a]]);
                // Show the shape client side. For this kind of shapes
                // the function for locally created shapes is the same
                // of that for remotely created shapes; we just omit
                // the 'id' so that a new one will be created
                this.server_create(par);
                // send server update
                sender_add(this.type, par, this.id);
            }
            textarea.value = '';
            this.close_shape();
        }
    };

    // This methods will be overriden by each specific shape, but for
    // shapes that don't use some of them, an empty definition is the
    // fallback
    object.mousedown = function(x, y){};
    object.mousemove = function(x, y){};
    object.mouseup   = function(){};
    return object;
}

/* shape_introduction:

  Here follow the different shapes derived from 'shape'. There are two
  kind of shapes, with similar behaviors within their groups:

  - dynamic shapes like line, rect, circle, polygon, path, etcetera:
    they have a corresponding svg element which must be updated before
    that the shape is finished. Usually they:

      - start the shape

      - update the state of the svg element responding to the user
        actions

      - end the shape and send the result to the server

  - static shapes (which use the textarea) like text, link and image:
    they collect the user input and then build the shape and send the
    result to the server. The creation of a shape on the part of an
    user, in this case, is similar to the creation of the shape by the
    part of the server (the whole shape is created in the same
    moment).

 */

/* A not active shape does nothing, it is not usable, its purpose is
 * just telling the caller that this shape is unusable */
function not_active_shape(){
    var object = shape('', []);
    object.active = false;
    return object;
}

function line(){
    var att = ['opacity','stroke-width','x1','y1','x2','y2'];
    var object = shape('line', att);

    object.mousedown = function(x, y){
        if (this.open)
            return;
        if (this.edit){
            var vertexes = new Array();
            var x1 = parseInt(this.x1) + this.offset_x;
            var y1 = parseInt(this.y1) + this.offset_y;
            var x2 = parseInt(this.x2) + this.offset_x;
            var y2 = parseInt(this.y2) + this.offset_y;
            vertexes[0] = {x: x1, y: y1};
            vertexes[1] = {x: x2, y: y2};
            // The fixed point of the line is the farthest from the
            // mouse
            var fixed;
            if (nearest_vertex(vertexes, {x:x, y:y}) == 0)
                fixed = 1;
            else
                fixed = 0;
            this.x1 = parseInt(vertexes[fixed]['x']);
            this.y1 = parseInt(vertexes[fixed]['y']);
        }
        else{
            this.x1 = x;
            this.y1 = y;
        }
        this.x2 = x;
        this.y2 = y;
        this.start_shape();
    };
    object.mousemove = function(x, y){
        if (!this.open)
            return;
        // Set the end point of the line        
        this.element.setAttribute('x2', x);
        this.element.setAttribute('y2', y);
    };
    object.mouseup = function(){
        if (!this.open)
            return;
        this.end_shape();
    };
    return object;
}

function circle(){
    var att = ['fill-opacity','stroke-width','cx','cy','r'];
    var object = shape('circle', att);

    object.mousedown = function(x, y){
        if (this.open)
            return;
        if(this.edit){
            this.cx = parseInt(this.cx) + this.offset_x;
            this.cy = parseInt(this.cy) + this.offset_y;
        }
        else{
            this.cx = x;
            this.cy = y;
        }
        this.r = 0;
        this.start_shape();
        // Just to update the aspect of the edited shape
        if(this.edit)
            this.mousemove(x, y);
    };
    object.mousemove = function(x, y){
        if (!this.open)
            return;
        // Compute and set the radius of the circle        
        var dx = this.cx - x;
        var dy = this.cy - y;
        var r = Math.sqrt(dx*dx + dy*dy);
        Math.round(r);
        this.element.setAttribute('r', r);
    };
    object.mouseup = function(){
        if (!this.open)
            return;
        this.end_shape();
    };
    return object;
}

function rect(){
    var att = ['fill-opacity','stroke-width','x','y','width','height'];
    var object = shape('rect', att);

    object.mousedown = function(x, y){
        if (this.open)
            return;
        if(this.edit){
            //find  rect vertexes from the svg element attributes
            this.x = parseInt(this.x) + parseInt(this.offset_x);
            this.y = parseInt(this.y) + parseInt(this.offset_y);
            var vertexes =
                Array(// 0 high left
                      {'x':this.x,
                       'y':this.y},
                      // 1 low left
                      {'x':this.x,
                       'y':parseInt(this.y)+parseInt(this.height)},
                      // 2 low right
                      {'x':parseInt(this.x)+parseInt(this.width),
                       'y':parseInt(this.y)+parseInt(this.height)},
                      // 3 high right
                      {'x':parseInt(this.x)+parseInt(this.width),
                       'y':this.y});
            var n = nearest_vertex(vertexes, {x:x, y:y})
                var starting = [2, 3, 0, 1];
            this.x = parseInt(vertexes[starting[n]]['x']);
            this.y = parseInt(vertexes[starting[n]]['y']);
        }
        else{
            this.x = x;
            this.y = y;
        }
        this.width = 0;
        this.height = 0;
        this.start_shape();
        // Just to update the aspect of the edited shape
        if(this.edit)
            this.mousemove(x, y);
    };
    object.mousemove = function(x, y){
        if (!this.open)
            return;
        // Calculate the position and dimensions based on which quadrant
        // we are

        var width  = x - this.x;
        if (width < 0){
            width = -width;
            this.element.setAttribute('x', x);
        }
        this.element.setAttribute('width', width);

        var height = y - this.y;
        if (height < 0){
            height = -height;
            this.element.setAttribute('y', y);
        }
        this.element.setAttribute('height', height);
    };
    object.mouseup = function(){
        if (!this.open)
            return;
        this.end_shape();
    };
    return object;
}

/* the difference between path and multipath is that multipath doesn't
 * do start_shape on mousedown, but only on shape creation */
function path(type){
    var att = ['opacity', 'stroke-width', 'd'];
    var object = shape('path', att);
    // The group is created here (once for all strokes) for mutipaths,
    // and into onmousedown for single paths
    if(type=='multipath'){
        object.create_group();
        object.single = false;
    }
    else
        object.single = true;

    object.mousedown = function(x, y){
        if (this.open)
            return;
        this.d = 'M'+x+','+y;
        this.fill = 'none';
        // This is like a start_shape function, but create the group
        // just if this isn't a multipath
        this.open_shape();
        if(this.single)
            this.create_group();
        this.create_element();
    };
    object.mousemove = function(x, y){
        if (!this.open)
            return;
        var d = this.element.getAttribute('d');
        d += ' L '+x+','+y;
        this.element.setAttribute('d', d);
        // firefox/native truncates the received updates, so don't let
        // this field become too long. This way, at least the user
        // knows what can be seen by everyone. I measured that the
        // field was truncated to 4096 chars. The final size of the
        // field also depends by the conversions made by the
        // size_adapter object (application.js), so even with this
        // check the final path could lose some of his last points,
        // but this is not a big problem
        if (d.length>4000)
            this.mouseup();
    };
    object.mouseup = function(){
        if (!this.open)
            return;
        this.end_shape();
    };
    return object;
}

/* polygons and polylines differ just into the type of the element to
 * be created, and the presence or not of the fill color. If the
 * function is called without arguments, it starts a polygon,
 * otherwise, it starts a polyline */
function polygon(type){
    if(!type){
        type = 'polygon';
        var att = ['fill-opacity', 'stroke-width', 'points'];
    }
    else{
        type = 'polyline';
        var att = ['opacity', 'stroke-width', 'points'];
    }
    var object = shape(type, att);

    // To store the last mouse coordinates, to recognize a double click
    object.x = -1;
    object.y = -1;

    /* A single click adds a point, a double click closes the
     * polygon */
    object.mousedown = function(x, y){
        // If the polygon is open, add a point or close the polygon
        if(this.open){
            // If this point is the same of the previous, close the
            // polygon (this.x and x are strange objects in the svgweb
            // environment, so they must be converted)
            if(parseInt(this.x) == parseInt(x) &&
               parseInt(this.y) == parseInt(y))
                this.end_shape();
            // If this point is different from the previous, add it to the
            // points and vertexes list and store mouse coordinates to wait
            // a double click
            else{
                this.points += ' '+x+','+y;
                this.vertexes.push({'x':x, 'y':y});
                object.x = x;
                object.y = y;
            }
        }
        // If the polygon isn't open, start a new one
        else{
            // An array to hold the polygon points
            this.vertexes = new Array();
            if(this.edit){
                // Transform points string to an array, adding the
                // translation to each point
                var pairs = this.points.split(' ');
                // Chrome's native renderer has a different syntax for points,
                // they are all divided just by whitespaces as in "2 3 2 4 5 6"
                if (sniff() == 'chrome' && svgweb.getHandlerType() == 'native')
                    // pairs.length is always even here
                    for(var i=0; i<pairs.length-1; i=i+2){
                        var vx = parseInt(pairs[i]  ) + this.offset_x;
                        var vy = parseInt(pairs[i+1]) + this.offset_y;
                        this.vertexes.push({'x': vx, 'y': vy});
                    }
                // For all other configurations, points are pairs of comma
                // separated values separated by whitespaces as in "2,3 2,4 5,6"
                else
                    for(var i=0; i<pairs.length; i++){
                        pair = pairs[i].split(',');
                        var vx = parseInt(pair[0]) + this.offset_x;
                        var vy = parseInt(pair[1]) + this.offset_y;
                        this.vertexes.push({'x': vx, 'y': vy});
                    }
                // Store the nearest index
                this.edit_nearest =
                    nearest_vertex(this.vertexes, {'x':x, 'y':y});
            }
            // If not editing
            else{
                this.points = x+','+y;
                this.vertexes.push({'x':x, 'y':y});
                if(this.type=='polyline')
                    this.fill = 'none';
            }
            this.start_shape();
            if(this.edit)
                this.mousemove(x, y);
        }
    };
    object.mousemove = function(x, y){
        if(!this.open)
            return;
        if(this.edit){
            this.vertexes[this.edit_nearest] = {'x':x, 'y':y};
            var points = this.points_from_vertexes();
        }
        else
            var points = this.points+' '+x+','+y;
        this.element.setAttribute('points', points);
    };
    // This shapes ends on mouseup when it is edited, but it ends in a
    // special case of mousedown when it's created
    object.mouseup = function(){
        if(this.edit)
            this.end_shape();
    };
    // Due to chrome's different point syntax, end_shape must be
    // overridden
    object.end_shape = function(){
        var params = [];
        for(a in this.att){
            if(this.att[a]=='points' && sniff() == 'chrome'
               && svgweb.getHandlerType() == 'native')
                params.push(this.points_from_vertexes());
            else
                params.push(this.element.getAttribute(this.att[a]));
        }
        // Add the element in the array that will be sent to the server.
        sender_add(this.type, params, this.id);
        this.close_shape();
    };
    object.points_from_vertexes = function(){
        var points = '';
        for(i=0; i<this.vertexes.length; i++)
            points += this.vertexes[i]['x'] +','+ this.vertexes[i]['y']+' ';
        // Get rid of trailing white space
        points = points.slice(0, -1);
        return points;
    };
    return object;
}

/* the following classes (text, link, image) must override the
 * server_create method, because they use a special way to build the
 * svg object. Furthermore, they use the server_create method even
 * when it's this user which is creating the shape (See
 * 'shape_introduction' above)*/

/*
  Structure of a text shape:
  <g id="1_2_3">
   <text fill="#abc123" x="5" y="30">
    <tspan x="5" y="30">first row</tspan>
    <tspan x="5" y="40">second row</tspan>
   </text>
  <g>
*/
function text(){
    var att=['x', 'y', 'text'];
    var object = shape('text', att);

    // Override the function copy_shape mantaining the most part of
    // his code
    object.parent_copy_shape = object.copy_shape;
    object.copy_shape = function(target){
        this.parent_copy_shape(target);
        // Retrieve the 'text' and put it into the textarea
        var rows = [];
        var childs = target.childNodes;
        for (var n=0; n<childs.length; n++)
            // Between the child nodes, there are a lot of nodes
            // which are not useful (comments and any kind of
            // oddity), so take only the tspans
            if (childs[n].nodeName=='tspan')
                // Read the data of the textnode which is the first
                // child of the <tspan>
                rows.push(childs[n].childNodes[0].data);
        getById('textinput').value = rows.join('\n');
    };

    object.mousedown = function(x, y){
        this.x = x;
        this.y = y;
        object.show_text_area(x, y);
    };

    object.server_create = function(par, id){
        this.create_group(id);
        var fill = par[1];
        var x    = parseInt(par[2]);
        var y    = parseInt(par[3]);
        if (id === undefined)
            //client call
            var content = unescape(unescape(par[4]));
        else
            //server call
            var content = unescape(par[4]);
        // Create the element (can't use create_element because this case
        // is not standard)
        this.element = document.createElementNS(svgns, 'text');
        sa(this.element, {'fill': fill, 'x':x, 'y':y});
        this.group.appendChild(this.element);
        var rows = content.split('\n');
        // Process rows to create a <tspan> for each row.  The vertical
        // coordinate ('y') will be incremented with rows
        for (var r=0; r < rows.length; r++){
            var tspan = document.createElementNS(svgns, 'tspan');
            // I found that characters like '<' are converted to their
            // corresponding HTML entities. I don't know if it is a
            // feature of the svgweb library or it is normal for
            // createTextNode. Differently, chat text is encoded to
            // HTML entities by the server-side code
            var tnode = document.createTextNode(rows[r], true);
            tspan.appendChild(tnode);
            tspan.setAttribute('x', x);
            tspan.setAttribute('y', y);
            this.element.appendChild(tspan);
            y = y + (g['fontSize']+1);
        }
        // In svgweb, text nodes can't inherit handlers from root like
        // other nodes do, so we have to add the handlers here
        if (svgweb.getHandlerType() == 'flash'){
            this.element.addEventListener('mousedown', handleMouseDown, false);
            this.element.addEventListener('mouseup', handleMouseUp, false);
            this.element.addEventListener('mousemove', handleMouseMove, false);
        }
    };
    return object;
}

/*
  Structure of a link shape:
  <g id="1_2_3">
   <text fill="#0000CD" x="5" y="30">
    <tspan visibility="hidden"></tspan>
    http://www.myurl.com
   </text>
  <g>
*/
function link(){
    var att=['x', 'y', 'text'];
    var object = shape('link', att);

    // Override the function copy_shape mantaining the most part of
    // his code
    object.parent_copy_shape = object.copy_shape;
    object.copy_shape = function(target){
        this.parent_copy_shape(target);
        // Read the url and put it into the textarea
        // (text.textnode.data)
        getById('textinput').value =
        target.childNodes[1].data;
    }

    object.mousedown = function(x, y){
        this.x = x;
        this.y = y;
        object.show_text_area(x, y);
    };

    object.server_create = function(par, id){
        this.create_group(id);
        var x = par[2];
        var y = par[3];
        if (id === undefined)
            //client call
            var url = unescape(unescape(par[4]));
        else
            //server call
            var url = unescape(par[4]);
        // Create the element (can't use create_element because this case
        // is not standard)
        this.element = document.createElementNS(svgns, 'text');
        sa(this.element, {'fill': '#0000CD', 'x':x, 'y':y});
        this.group.appendChild(this.element);
        // First add an hidden tspan to distinguish the link from plain
        // text (this is read into handleMouseDown, branch "select")
        var tspan = document.createElementNS(svgns, "tspan");
        tspan.setAttribute('visibility', 'hidden');
        this.element.appendChild(tspan);
        // Add url content
        var tnode = document.createTextNode(url,true);
        this.element.appendChild(tnode);
        // In svgweb, text nodes can't inherit handlers from root like
        // other nodes do, so we have to add the handlers here
        if (svgweb.getHandlerType() == 'flash'){
            this.element.addEventListener('mousedown', handleMouseDown, false);
            this.element.addEventListener('mouseup', handleMouseUp, false);
            this.element.addEventListener('mousemove', handleMouseMove, false);
        }
    };

    return object;
}

function image(){
    var att = ['x', 'y', 'text', 'width', 'height'];
    var object = shape('image', att);

    // These will be the sizes (in pixels) of the temporary
    // image. They will be sent to the server but ignored
    object.width  = 90;
    object.height = 90;

    // Override the function copy_shape mantaining the most part of
    // his code
    object.parent_copy_shape = object.copy_shape;
    object.copy_shape = function(target){
        this.parent_copy_shape(target);
        // Retrieve the url and put it into the textarea
        getById('textinput').value =
        target.getAttributeNS(xlinkns, 'href');
    }

    object.mousedown = function(x, y){
        this.x = x;
        this.y = y;
        object.show_text_area(x, y);
    };

    object.server_create = function(par, id){
        this.create_group(id);
        // the 'image' shape distinguishes between a client creation (with
        // temporary image) and a server one, while for 'text' and 'link'
        // shapes server creation and client creation are the same
        if(id)
            this.server = true;
        else
            this.server = false;
        // This could be a server image ready to substitute an user's
        // temporary image. In this case, don't create a new image but
        // change the attributes of the existing one
        if(this.group.childNodes[0])
            this.element = this.group.childNodes[0];
        else
            this.element = document.createElementNS(svgns, this.type);
        // Set attributes taking care of xlink:href, which requires
        // setAttributeNS
        for(a in this.att){
            if(this.att[a]=='text'){
                if(this.server)
                    this.element.setAttributeNS(xlinkns, 'xlink:href',
                                                unescape(par[a]));
                else
                    this.element.setAttributeNS(xlinkns, 'xlink:href',
                                                'images/image_wait.png');
            }
            else
                this.element.setAttribute(this.att[a], par[a]);
        }
        this.group.appendChild(this.element);
    };

    return object;
}

// Returns the index of the point into the array which is nearest to
// the given point. This is used by shapes line, rect and poly to find
// the vertex which is to be moved
function nearest_vertex(vertexes, point){
    var nearest = 0;
    var diffx = parseInt(vertexes[nearest]['x']) - parseInt(point['x']);
    var diffy = parseInt(vertexes[nearest]['y']) - parseInt(point['y']);
    var nearest_dist = Math.sqrt((diffx*diffx) + (diffy*diffy));
    var dist;
    for(i=1; i<vertexes.length; i++){
        diffx = parseInt(vertexes[i]['x']) - parseInt(point['x']);
        diffy = parseInt(vertexes[i]['y']) - parseInt(point['y']);
        dist = Math.sqrt((diffx*diffx) + (diffy*diffy));
        if(dist<nearest_dist){
            nearest = i;
            nearest_dist = dist;
        }
    }
    return nearest;
}
