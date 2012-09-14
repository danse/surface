// $Id: chat.js 132 2010-12-13 10:53:17Z s242720-studenti $

function chatServerUpdate(madeBy, objId, par, time){
    var date = new Date(time*1000);
    var text = unescape(par[0]);
    showMessage(objId, madeBy, date, text, true);
}

function chatOnKeyUp(e) {
    key = cross_which(e);
    // This function should continue only if the user pressed just
    // "enter" (and not "shift+enter")
    if (key == 13 && !cross_shift(e)){
        var ti = getById('text_input_t');
        // Trim the trailing newline
        var text = ti.value.slice(0, -1);
        // If the text is not empty send an update to the server and
        // show the message
        if (text!='') {
            var objId = g['idObj'].get_new();
            // Escape the text for separators like :, |, etcetera. It
            // must be unescaped client side by the chatServerUpdate
            // function, and server side where needed. The escape is
            // done two times, because it is unescaped once by the
            // server while reading the POST ajax request, whose mime
            // type is "application/www-form-urlencoded"
            var escaped_text = escape(escape(text));
            var parameters = [escaped_text];
            var pagecp = g['page'];
            g['page'] = -1;
            sender_add('chat', parameters, objId);        
            g['page'] = pagecp;
            showMessage(objId, S['user'], '', text, false);
        }
        ti.value = '';
    }
    return false;
}

/*
 * If called by server, show or confirm a message; if called by the
 * user, show an unconfirmed message and send the update to the server
 */
function showMessage(objId, sender, d, text, server){
    // This is changed if the server is going to confirm an existing message
    var new_message = true;
    // If showMessage is called by a server update set the proper
    // style, and add the date
    if(server){
        var skin = g['skin_names'][g['current_skin_id']];
        // For example: Wed Dec 09 14:44
        var date_string = (parseInt(d.getUTCMonth())+1)+' '+d.getUTCDate()+' '+
            d.getUTCHours()+':'+d.getUTCMinutes();
        // If the sender is this user
        if(sender==S['user']){
            var div_from_class = 'div_from_me_s_'+skin;
            var div_message = getById(objId);
            // If the message already exists, retrieve its elements for
            // confirmation (that means to change their classes), and add
            // the date
            if(div_message){
                var div_from = div_message.childNodes[0];
                var div_date = div_message.childNodes[1];
                div_date.appendChild(document.createTextNode(date_string));
                var div_text = div_message.childNodes[2];
                var hr = div_message.childNodes[3];
                new_message = false;
            }
        }
        // If the sender is not this user
        else
            var div_from_class = 'div_from_s_'+skin;
    }
    // If showMessage is not called by the server (it's called by
    // onkeyup event raised by the user) set the 'unconfirmed' style
    else{
        var skin = 'unconfirmed';
        var div_from_class = 'div_from_me_s_unconfirmed';
    }
    // Both server and user's calls may need to build a new message div
    if(new_message){
        // Create main divs
        var div_from = document.createElement('div');
        var div_date = document.createElement('div');
        var div_text = document.createElement('div');
        var hr = document.createElement('hr');
        var div_message = document.createElement('div');
        div_message.setAttribute('id', objId);

        // Append the divs into the message div, append the message div into
        // the text output div
        div_from.appendChild(document.createTextNode(sender));
        // 'unconfirmed' user messages don't have a date (in that case,
        // 'time' is undefined)
        if(date_string)
            div_date.appendChild(document.createTextNode(date_string));
        textRender(unescape(text), div_text);
        div_message.appendChild(div_from);
        div_message.appendChild(div_date);
        div_message.appendChild(div_text);
        div_message.appendChild(hr);
        getById('text_output').appendChild(div_message);
    }
    // Both new and confirmed messages need to change their class names
    div_message.className = 'div_message_s_'+skin;
    div_from.className = div_from_class;
    div_date.className = 'div_date_s_'+skin;
    div_text.className = 'div_text_s_'+skin;
    hr.className = 'hr_s_'+skin;
    // Adjust scroll (always go to the bottom)
    getById('text_output').scrollTop = getById('text_output').scrollHeight;
}

/*
  Takes a text and puts it into p:
  - substituting the emoticons with corresponding images
  - putting anchors around http:// links
  - replacing newlines with "br"
 */
function textRender(utext, p)
{        
    var utextArray = utext.split('\n');
    for (var i = 0; i < utextArray.length; i++) {
        var v = utextArray[i].split(" ");
        for (var j = 0; j < v.length; j++) {
            var src = "";
            switch(v[j]){                        
                case ":)":                                        
                    src = "smile.gif";        
                    break;                                
                case ":(":
                    src = "sad.gif";
                    break;
                case ":P":
                    src = "tongue.gif";
                    break;
                case ":O":
                    src = "wonder.gif";
                    break;                        
                case ":D":
                    src = "laugh.gif";
                    break;
                default:                                        
                    break;
            }
            // emoticon generation
            if (src != "") {
                var img = document.createElement("img");
                img.setAttribute("src", "images/emot_" + src);
                img.setAttribute("alt", v[j]);
                img.setAttribute("width", "16px");
                img.setAttribute("height", "16px");                
                p.appendChild(img);                        
                p.appendChild(document.createTextNode(" "));
            }
            else {
                if (!v[j].match("http://"))
                    p.appendChild(document.createTextNode(v[j]));
                else // anchor generation
                    {
                        var a = document.createElement("a");
                        a.setAttribute("href", v[j]);
                        a.setAttribute("target", "_blank");
                        a.appendChild(document.createTextNode(v[j]));
                        p.appendChild(a);
                    }
                p.appendChild(document.createTextNode(" "));        
            }                        
        }
        if (i < utextArray.length - 1)
            p.appendChild(document.createElement("br"));
    }
}

// remove all elements from the chat window
function clearChat() {
    var div = getById("text_output");
    while (div.hasChildNodes())
        div.removeChild(div.firstChild);
}
