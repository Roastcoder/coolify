// StandaloneCoders Mail: the "Remember me" box on the sign-in page, and the account menu in the top bar.
document.addEventListener('DOMContentLoaded', function () {
    var buttons = document.querySelector('#login-form .formbuttons');
    if (buttons && !document.querySelector('.sc-remember')) {
        var remember = document.createElement('label');
        remember.className = 'sc-remember';
        remember.innerHTML = '<input type="checkbox" name="_remember" value="1"> Remember me for 30 days';
        buttons.parentNode.insertBefore(remember, buttons);
    }
});

document.addEventListener('DOMContentLoaded', function () {
    var button = document.querySelector('#sc-topbar .sc-account-button');
    var menu = document.querySelector('#sc-topbar .sc-menu');
    if (!button || !menu) {
        return;
    }

    function setOpen(open) {
        menu.hidden = !open;
        button.setAttribute('aria-expanded', open ? 'true' : 'false');
        if (open) {
            menu.querySelector('a').focus();
        }
    }

    button.addEventListener('click', function (event) {
        event.stopPropagation();
        setOpen(menu.hidden);
    });
    document.addEventListener('click', function (event) {
        if (!menu.hidden && !menu.contains(event.target)) {
            setOpen(false);
        }
    });
    document.addEventListener('keydown', function (event) {
        if (event.key === 'Escape' && !menu.hidden) {
            setOpen(false);
            button.focus();
        }
    });
});
