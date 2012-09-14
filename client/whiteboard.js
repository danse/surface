// $Id: whiteboard.js 113 2010-11-10 12:18:41Z s242720-studenti $


/* Constants */

// Size of the Font
g['fontSize'] = 15;

/* Initial state */

// Initial tool
g['tool'] = 'move';
// Global variable holding the object currently moved by the user
g['moving_object'] = null;
// Useful for 'move' and 'delete'. Set true for the mousedown event,
// set false for the mouseup event
g['active_tool'] = false;
// Initial mouse position, useful for actions which require a
// difference of two positions like 'move'
g['p'] = {x:0, y:0};

/* Description for variables initialized elsewhere */

// Canvas offsets, for firefox native svg reader, that takes absolute
// mouse position from events. Initialized by update_canvas_offsets
g['x_offset'];
g['y_offset'];
// Reference to the HTML element corresponding to the last pressed
// button. Initialized by initWhiteboard
g['pressedButton'];

function initWhiteboard(){
    /* Add event hanlers. For this purpose I use 'addEventListener'
     * because this method is well supported by svgweb library
     * (instead of using 'addAttribute' or writing event handlers
     * directly into html) */
    var svg_root = getById('svg_root');
    svg_root.addEventListener('mousedown', handleMouseDown, false);
    svg_root.addEventListener('mouseup'  , handleMouseUp  , false);
    svg_root.addEventListener('mousemove', handleMouseMove, false);

    update_canvas_offsets();

    // Creating the initial page
    g['pages'].init();

    // Initial shape and initial additional panel. These will be
    // changed within handleTool, but must have an initial value
    g['shape'] = g['shape_generator'].generate_shape(g['tool']);
    g['additional_panel'] = '';
    // Settings related to the initial tool (build a fake event)
    handleTool({target:getById(g['tool']+'_button')});
}

g['pages'] = {
    // 'current' could be read from the outside
    current: 0,
    // An array containing references to all page elements
    index: {},
    /* All public methods */
    init: function(){
        this.add(this.current);
        this.index[this.current].setAttribute('style', '');
    },
    // Add the page with page number 'n'
    add: function(n){
        var page = document.createElementNS(svgns, 'g');
        this.index[n] = page;
        page.setAttribute('style', 'display:none');
        page.setAttribute('id', 'g'+n);
        getById('whiteboard_g').appendChild(page);
    },
    // Append a node to a page
    append: function(node, i){
        if(i == undefined)
            i = this.current;
        if(this.index[i] == undefined)
            this.add(i);
        this.index[i].appendChild(node);
    },
    // Remove a node from a page
    remove: function(node, page){
        if(page == undefined)
            page = this.current;
        this.index[page].removeChild(node);
    },
    // Remove all nodes on a page keeping the page
    clear: function(i){
        if(i == undefined)
            i = this.current;
        if(this.index[i] != undefined){
            var page = this.index[i];
            while (page.hasChildNodes())
                page.removeChild(page.lastChild);
        }
    },
    // Remove all nodes on all pages removing also the pages
    reset: function(){
        for(i in this.index)
            getById('whiteboard_g').removeChild(this.index[i]);
        this.index = {};
        this.init();
    },
    // swap, up, down: methods to change the current page
    swap: function(n){
        if(n != this.current){
            if(this.index[n]==undefined)
                this.add(n);
            this.index[this.current].setAttribute('style', 'display:none');
            this.index[n].setAttribute('style', '');
            getById('page_input').value = n;
            this.current = n;
        }
    },
    up: function(){
        if(this.current<99)
            this.swap(this.current+1);
    },
    down: function(){
        if(this.current>0)
            this.swap(this.current-1);
    }
};

// Some objects (like text) are nested inside others while firing
// handlers, so sometimes it is necessary to climb up the hierarchy to
// find the containing group ('g') tag, which is needed for moving and
// deleting operations. What is the exact child that fires an handler
// can depend also on the renderer in use.
function find_group(element){
    while(element.nodeName != 'g'){
        mylog('nested object, climbing up hierarchy...');
        element = element.parentNode;
    }
    return element;
}

/*
 * This function must be called on the whiteboard initialization and
 * whenever a movement of the canvas on the page occurs (not a
 * movement for the scroll, that is handled differently), to update
 * svg root's x and y coordinates that are needed by some browsers to
 * correctly place mouse events on the canvas
 */
function update_canvas_offsets(){
    // Set initial svg canvas offset
    var container = getById('svg_container');
    var top  = 0;
    var left = 0;
    do{
        top  += container.offsetTop;
        left += container.offsetLeft;
        container = container.offsetParent;
    }
    while(container != null);
    g['x_offset'] = left;
    g['y_offset'] = top;
}

/*
 * Compute a skew on a pair of coordinates, adding effects of
 * scrolling and adding the offsets of the svg root node, depending on
 * the renderer and the kind of coordinates. See "Coordinates within
 * the svg canvas" into the developer guide
 */
function skew(coords, pointer){
    // Look upon the page scroll. By default, don't apply scroll (this
    // applies to firefox/flash, and chrome non pointer coordinates)
    var X = coords.x;
    var Y = coords.y;
    if (svgweb.getHandlerType() == 'native'){
        // Firefox/native
        if (sniff() == 'firefox'){
            X = X + window.pageXOffset - g['x_offset'];
            Y = Y + window.pageYOffset - g['y_offset'];
        }
        // Crome/native: apply scroll just to pointer coordinates
        else
            if (pointer){
                X = X + window.pageXOffset - g['x_offset'];
                Y = Y + window.pageYOffset - g['y_offset'];
            }
    }
    return {'x': X, 'y': Y};
}

/*
 * Get mouse coordinates inside the svg canvas
 */
function mouseCoordsSvg(evt) {
    return skew({x:evt.clientX, y:evt.clientY}, true);
}

/*
 * Handler of the onclick event on the toolbox buttons (for colors see
 * handleStroke, handleFill)
 */
function handleTool(event){
    // Don't change the button until the user hasn't finished his draw
    if(g['shape'].open || g['active_tool']){
        user_help('close_draw');
        return;
    }
    var target = cross_target(event);
    // take the element type from attributes of the buttons
    var el = target.getAttribute('value');
    var el = target.getAttribute('value').toLowerCase();
    if(el=='<'){
        g['pages'].down();
        return;
    }
    if(el=='>'){
        g['pages'].up();
        return;
    }
    if(el=='clear'){
        g['pages'].clear();
        sender_add('clear');
        return;
    }
    // Clicking on the 'path' button switches between single path and
    // multipath
    if(el == 'path'){
        if(g['tool'] == 'path')
            g['tool'] = 'multipath';
        else
            g['tool'] = 'path';
    }
    else
        g['tool'] = el;

    // Unselect the old pressed button if necessary
    if (g['pressedButton'])
        g['pressedButton'].className = 'draw_button';
    // Highlight the new pressed button. The 'multipath' case has a
    // special style
    g['pressedButton'] = getById(target.id);
    if(g['tool']=='multipath')
        g['pressedButton'].className = 'draw_button_special';
    else
        g['pressedButton'].className = 'draw_button_pushed';

    show_additional_panel(g['tool']);
    // Set the new shape, if one
    g['shape'] = g['shape_generator'].generate_shape(g['tool']);
    // Show a short tip about the current tool
    user_help(g['tool']);
    // Sometimes the canvas offsets could be outdated due to little
    // recenterings of the whiteboard. This is an additional
    // (redundant) measure to make sure that canvas offsets are fine
    // before starting to draw
    update_canvas_offsets();
}

function handlePageInput(event){
    var key = cross_which(event);
    if(key == 13){
        var target = cross_target(event);
        g['pages'].swap(parseInt(target.value));
    }
}

/*
 * Show an additional panel with other input fields, if the tool (or
 * shape) needs one
 */
function show_additional_panel(tool){
    var new_panel = '';
    switch(tool){
    case 'polyline':
    case 'line':
    case 'path':
    case 'multipath':
        new_panel = 'open_shape_style';
        break;
    case 'polygon':
    case 'rect':
    case 'circle':
        new_panel = 'closed_shape_style';
        break;
    case 'image':
        new_panel = 'image_style';
        break;
    }
    if(g['additional_panel'] != new_panel){
        if(g['additional_panel'] != '')
            // Hide old panel
            getById(g['additional_panel']).className = 'hidden';
        if(new_panel != ''){
            var panel = getById(new_panel);
            // Show the new panel
            panel.className = 'new_panel';
        }
        // Save changes
        g['additional_panel'] = new_panel;
    }
}

/*
 * Handler of the onmousedown event.
 */
function handleMouseDown(evt)
{
    mylog('Started handleMouseDown with target nodeName '+evt.target.nodeName);
    // With svgweb, mousedown on text elements causes also mousedown on
    // the "svg" node on the back, so this check nullifies the second
    // function call
    if (evt.target.nodeName == 'svg')
        return;
    // This is necessary with firefox native renderer, otherwise the
    // "mousedown" event would cause the attempt of dragging svg
    // elements
    evt.preventDefault();

    var p = mouseCoordsSvg(evt);

    if(g['shape'].active)
        // When the current tool corresponds to a shape, mouse events are
        // forwarded to the shape
        g['shape'].mousedown(p.x, p.y);
    else{
        // Other tools not binded with shapes, but that act on a
        // clicked shape. First ignore the operation if the target is
        // 'canvas', then retrieve the shape parent group and execute
        // the action
        var target = evt.target;
        if (target.id == 'canvas'){
            mylog('Ignoring attempt to act on the canvas');
            if (g['tool']=='delete')
                // Activate the "dragging delete"
                g['active_tool'] = true;
        }
        else{
            // To search the group and then to come back to the childs
            // can look weird, but this is useful because changing the
            // renderer, the element which raises the event (for
            // example in a text structure) may change
            var group = find_group(target.parentNode);
            switch(g['tool']){
            case 'select':
                var shape = group.childNodes[0];
                // Select is useful only for the links, which have
                // 'text' as top node. They can distinguished by plain
                // text elements because they have a first <tspan>
                // element with visibility="hidden"
                if (shape.nodeName == 'text' &&
                    shape.childNodes[0].getAttribute('visibility')=='hidden'){
                    var url = shape.childNodes[1].data;
                    // Urls without the http look nicer, but it has to
                    // be added, otherwise they will be taken as
                    // relative links
                    if (!/http/.test(url))
                        url = 'http://'+url;
                    window.open(url);
                }
                break;
            case 'edit':
                var shape = group.childNodes[0];
                // Paths can't be edited
                if (shape.nodeName == 'path')
                    mylog('Ignoring attempt to edit a path');
                else{
                    // Distinguish a 'link' shape
                    if (shape.nodeName == 'text' &&
                        shape.childNodes[0].getAttribute('visibility')=='hidden')
                        var shape_name = 'link';
                    else
                        var shape_name = shape.nodeName;
                    show_additional_panel(shape_name);
                    // Copy the old object
                    g['shape'] = g['shape_generator'].generate_shape(shape_name);
                    g['shape'].copy_shape(shape);
                    g['shape'].mousedown(p.x, p.y);
                    // Delete the old object: sender_add is called
                    // with the asynchronous flag set to true, so the
                    // delete update will be sent together with the
                    // new shape update.
                    var group_id = group.getAttribute('id');
                    sender_add('delete', [], group_id, true);
                    g['pages'].remove(group);
                }
                break;
            case 'delete':
                delete_object(group);
                g['active_tool'] = true;
                break;
            case 'move':
                g['moving_object'] = group;
                var transMatrix = g['moving_object'].getCTM();
                // g['p'] will be read on mouse up, and to correctly
                // compute the new transformation we must consider the
                // original position of the object, not the one seen
                // by the user and the pointer
                p.x = p.x - Number(transMatrix.e);
                p.y = p.y - Number(transMatrix.f);
                g['active_tool'] = true;
                break;
            }
        }
    }
    // Store the initial mouse position for the other event handlers
    g['p'] = p;
}

/*
 * MouseMove -- update the geometry of the current object. If there
 * is an open shape, it handles mouse events. Otherwise, maybe the
 * user is trying to move an object or to delete objects dragging the
 * mouse with the 'delete' tool activated
 */
function handleMouseMove(evt) {
    evt.preventDefault();
    var p = mouseCoordsSvg(evt);
    if(g['shape'].active){
        g['shape'].mousemove(p.x, p.y);
    }
    else if (g['active_tool']){
        if (g['tool']=='move'){
            var t = [];
            t.x = p.x - g['p'].x;
            t.y = p.y - g['p'].y;
            g['moving_object'].setAttribute('transform',
                                            'translate('+t.x+','+t.y+')');
        }
        else if (g['tool']=='delete'){
            var target = evt.target;
            if (target.NodeName !== 'svg' && target.id !== 'canvas'){
                var group = find_group(target.parentNode);
                delete_object(group);
            }
        }
    }
}

/*
 * MouseUp -- send finished drawing to the server
 */
function handleMouseUp(evt)
{
    mylog('Started handleMouseUp with target nodeName '+evt.target.nodeName);
    
    if (g['shape'].active){
        g['shape'].mouseup();
    }
    else if (g['active_tool']){
        if (g['tool']=='move'){
            var p = mouseCoordsSvg(evt);
            var t = [];
            t.x = p.x - g['p'].x;
            t.y = p.y - g['p'].y;
            parameters = [t.x, t.y];
            objId = g['moving_object'].getAttribute('id');
            sender_add('move', parameters, objId);
            g['moving_object'] = null;
        }
        g['active_tool'] = false;
    }
}

/* Handler of stroke color buttons */
function handleStroke(event) {
    mylog('handleStroke');
    var target = cross_target(event);
    var new_class = target.className;
    getById('stroke_sample').className = new_class;
    // Extract the color from the class name, that is like:
    // "class_sample class_000000"
    var new_color = '#' + new_class.split(' ')[1].split('_')[1];
    g['shape'].stroke = new_color;
    g['shape_generator'].default_values.stroke = new_color;
}

/* Handler of fill color buttons */
function handleFill(event) {
    var target = cross_target(event);
    var new_class = target.className;
    getById('fill_sample').className = new_class;
    // Extract the color from the class name, that is like:
    // "class_sample class_000000"
    var new_color = '#' + new_class.split(' ')[1].split('_')[1];
    g['shape'].fill = new_color;
    g['shape_generator'].default_values.fill = new_color;
}

function whiteboardOnKeyUp(e) {
    // The corresponding event is raised only by the visible text area,
    // and the text area is visible only when a shape is present
    g['shape'].onkeyup(e);
}

/* Validation on shape attributes. It is used for additional panels */
function set_shape_attribute(name, event, regexp){
    var target = cross_target(event);
    var valid = validate(target, regexp);
    if (valid){
        g['shape'][name] = target.value;
        g['shape_generator'].default_values[name] = target.value;
        // The two inputs 'open_stroke' and 'closed_stroke' must hold
        // the same value, because they both refer to the same
        // variable, the stroke width
        if (name === 'stroke-width')
            getInput('open_stroke').value = getInput('closed_stroke').value = target.value;
    }
}

function delete_object(group){
    // Delete the selected object
    g['pages'].remove(group);
    // Add the delete operation to the update buffer
    sender_add('delete', [], group.getAttribute('id'));
}

function whiteboardServerUpdate(objId, page, typ, par){
    var shape = g['shape_generator'].generate_shape(typ, page);
    // If this is an action involving a shape
    if(shape.active)
        shape.server_create(par, objId);
    else
        switch(typ){
        case 'move':
            var group = getById(objId);
            if (group) {
                var transform = 'translate('+par[0]+','+par[1]+')';
                group.setAttribute('transform', transform);
            }
            // This can happen if someone deletes a object while someone
            // else is moving it
            else
                mylog('Cannot move nonexistent object ' + objId);
            break;

        case 'delete':
            var group = getById(objId);
            // make sure the object has not been already deleted (there is no
            // server side check, and the cuncurrency allows two delete
            // operations on the same object)
            if (group)
                g['pages'].remove(group, page);
            break;

        case 'clear':
            g['pages'].clear(page);
            break;
        }
}
