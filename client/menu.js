// $Id: menu.js 113 2010-11-10 12:18:41Z s242720-studenti $

function initMenu() {
    // This (skin image creation) could be moved to server side
    var p = getById('style_div');
    for (var i = 0; i < g['skin_names'].length; i++) {
        var skin = g['skin_names'][i];
        var img = document.createElement('img');
        var attributes = {'id' : 'img_'+i,
                          'src': 'images/skin_'+skin+'.gif',
                          'alt': skin};
        sa(img, attributes);
        // The default selected skin
        if (i==0)
            img.className = 'img_selected_s_default';
        else
            img.className = 'skin';
        // This attribute must be set differently with explorer
        if (img.attachEvent)
            img.attachEvent('onclick', changeSkin);
        else
            img.addEventListener('click', changeSkin, false);
        p.appendChild(document.createTextNode(' '));
        p.appendChild(img);                        
    }
}

/* 'logout' and 'del_ses' are directly binded to menu buttons */
function logout(msg){
    // This message will be shown on the new login page
    if (!msg)
        var msg = 'User logged out successfully';
    var query = {'mode':'logout', 'client_id':S['client_id'], 'message':msg};
    send_post('index.php', query);
}
function del_ses(){
    if(confirm('Do you really want to delete the session? (All whiteboard pages and chat messages will be deleted)')){
        var query = {'mode':'delete', 'client_id':S['client_id']};
        send_post('index.php', query);
    }
}

/*
 * Show a form (for complex operations) into the control panel
 */
function show_div(name, show){
    if(show){
        getById('control_default').style.display = 'none';
        getById(name).style.display = '';
    }
    else{
        getById(name).style.display = 'none';
        getById('control_default').style.display = '';
    }
    // The whiteboard could be recentered after the appearence of a
    // wider menu div, so the offsets of the SVG canvas must be
    // updated
    update_canvas_offsets();
}

/*
 * Send the input form, adding the missing values as hidden inputs
 */
function import_submit(){
    var import_form = getById('import_form');
    var inputs = {'user': S['user'],
                  'client_id': S['client_id'],
                  'signature': g['signer'].get_signature()};
    for(key in inputs){
        var input = document.createElement('input');
        sa(input,{'type':'hidden','name':key,'value':inputs[key]});
        import_form.appendChild(input);
    }
    // global variable to set when changing the page, see the
    // description on the top of common.js file
    g['abort_all_ajax_requests'] = true;
    // To upload in an asynchronous way (see
    // http://www.openjs.com/articles/ajax/ajax_file_upload/)
    import_form.target = 'import_target_frame';
    import_form.submit();
    show_div('menu_import', false);
}

/*
 * Adapt the appearence of the export div depending on choosen options
 */
function export_mode_change(event){
    var select = cross_target(event);
    var value = select.value;
    // To simplify the code we should keep the previous value
    switch(value){
    case 'interval':
        getById('export_extension_choice').className = 'hidden';
        getById('export_pages').className = 'inline';
        break;
    case 'current':
        getById('export_pages').className = 'hidden';
        getById('export_extension_choice').className = 'inline';
        break;
    case 'all':
        getById('export_pages').className = 'hidden';
        getById('export_extension_choice').className = 'hidden';
        break;
    case 'chat':
        getById('export_pages').className = 'hidden';
        getById('export_extension_choice').className = 'hidden';
        break;
    }
}

/*
 * The export form doesn't have a 'submit' button, its contents are
 * processed by this javascript function that sends a GET request and
 * opens a new page for the result (but the php code tells to the
 * browsers to save the file that will be showed)
 */
function export_submit(){
    var export_form = getById('export_form');
    var query = 'mode=export';

    // Page intervals can be exported only in pdf format. Here the
    // selected extension rules on the selected mode
    if(export_form['export_extension'].value != 'pdf')
        export_form['exp_mode'].value = 'current';
    switch(export_form['exp_mode'].value){
    case 'current':
        // If the choosen mode is 'current', send the value of the
        // current page instead of the value of the starting page
        getInput('exp_from').value = g['pages'].current;
        break;
    case 'all':
    case 'interval':
        // Page intervals can be exported only as pdf files
        getInput('export_extension').value = 'pdf';
        break;
    }

    // Values to be retrieved from form inputs (we don't care if some of
    // them isn't needed with some export mode)
    var inputs = ['exp_mode', 'exp_from', 'exp_to', 'export_extension'];
    for(i in inputs)
        query += '&'+inputs[i]+'='+export_form[inputs[i]].value;
    query += '&client_id='+S['client_id'];
    // Add the signature
    query += '&signature='+g['signer'].get_signature();
    window.open('index.php?'+query);
    show_div('menu_export', false);
}

/*
 * Append an iframe to the slides div and resize the chat to show it
 */
function load_slides(url){
    // If url is not defined, the function is called by the
    // user. Otherwise, it is called during the whiteboard
    // initialization
    if (!url){
        var url = getInput('slides_address').value;
        var user = true;
    }
    else
        var user = false;
    // Try to add http:// if it is missing
    if (!url.match(/http:\/\//))
        url = 'http://'+url;
    // Remove the old iframe if it exists
    if(getById('slides_frame'))
        getById('right_bottom').removeChild(getById('slides_frame'));
    var new_iframe = document.createElement('iframe');
    sa(new_iframe, {'id':'slides_frame', 'src':url, 'width':'100%',
                'height':'100%', 'onload':'this.contentWindow.focus()'});
    // Listen onload event (special case for Explorer)
    if(sniff() == 'explorer'){
        var load_func =
            function(){getById('slides_frame').contentWindow.focus();};
        new_iframe.attachEvent('onload', load_func);
    }
    getById('right_bottom').appendChild(new_iframe);
    // Toggle slides visibility
    right_resize('mid');
    // Escape the text for separators like :, /, etcetera. It must be
    // unescaped client side and server side where needed. The escape is
    // done two times, because it is unescaped once by the server while
    // reading the POST ajax request, whose mime type is
    // "application/www-form-urlencoded"
    if (user)
        sender_add('slides', [escape(escape(url))]);
    show_div('menu_slides', false);
}

/*
 * Remove the iframe and resize the chat to hide the slides div
 */
function hide_slides(){
    // Remove the old iframe if it exists
    if(getById('slides_frame'))
        getById('right_bottom').removeChild(getById('slides_frame'));
    // Toggle slides visibility
    right_resize('max');
    sender_add('slides', ['']);
    show_div('menu_slides', false);
}

/*
 * Ask to the receiver object to refresh all database lines
 */
function refresh(){
    // To perform a refresh, the following set instruction is the only
    // necessary one. The following function call is to restart the
    // receive cycle.
    g['receiver'].refresh = true;
    // This call allows to restart update receiving, in case an error
    // had stopped their cycle. If that cycle is up, the new call will
    // be ignored.
    receiver_send();
}

// Skin values for style changing
g['skin_names'] = ['default', 'facebook', 'msn'];
g['current_skin_id'] = 0;

function changeSkin(event) {
    target = cross_target(event);
    skin_id = parseInt(target.id.slice(-1));
    if (skin_id == g['current_skin_id'])
        return;
    else{
        // Change selected skin image
        var old_img = getById('img_'+g['current_skin_id']);
        old_img.className='skin';
        var img = getById('img_'+skin_id);
        img.className = 'skin img_selected_s_'+g['skin_names'][skin_id];
        // Change container class
        getById('container_div').className =
            'container_div_s_'+g['skin_names'][skin_id];
        // Change the class of chat messages
        recursiveChange(getById('text_output'),
                        g['skin_names'][g['current_skin_id']],
                        g['skin_names'][skin_id]);
        // Update current skin
        g['current_skin_id'] = skin_id;
    }
}

function recursiveChange(elem, old_skin, new_skin){
    if(elem.className && elem.className.match(old_skin))
        elem.className = elem.className.replace(old_skin, new_skin);
    if(elem.childNodes[0])
        for(var i=0; i<elem.childNodes.length; i++)
            recursiveChange(elem.childNodes[i], old_skin, new_skin);
}
