String.prototype.replaceAt = function(index, replacement) {
    return this.substring(0, index) + replacement + this.substring(index + replacement.length);
}

getJSON = function(url,data){
    return new Promise(function(resolve,reject)
    {
        var req = new XMLHttpRequest();
        req.open('POST', url, true);
        req.setRequestHeader('Content-type', 'application/x-www-form-urlencoded');
        req.onreadystatechange = function () {
            if (req.readyState == 4) {
                if(req.status == 200)
                    resolve(JSON.parse(req.responseText));
                else
                    reject(Error(req.statusText));
            }
        };
        req.onerror = function() {
            reject(Error("network error"));
        };
        req.send(data);
    });
};

validAjaxUrl = function(controller = null){
    const split_pathname = location.pathname.split("/");

    if(controller == null){
        return `${location.origin}/${split_pathname[1]}/${split_pathname[2]}/${split_pathname[3]}/`;
    }

    return `${location.origin}/${split_pathname[1]}/${split_pathname[2]}/${controller}/`;

}


function showHideRow(className) {
    [...document.getElementsByClassName(className)].forEach(node => node.classList.toggle('hideRow'));
}
