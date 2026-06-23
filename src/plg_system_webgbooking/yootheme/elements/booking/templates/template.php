<?php

/**
 * WebG Booking element — render template (UIkit markup + interactive widget).
 * YOOtheme contract: $this->el(), $props, $attrs, $children, $builder.
 *
 * Calendly-style flow: month CALENDAR -> time slots -> details FORM -> confirm.
 * Availability is demo data for now; real data comes from the com_webgbooking engine.
 * All visitor-facing strings are translatable (Joomla language, IT/EN shipped).
 *
 * @license GNU General Public License version 2 or later
 */

use Joomla\CMS\Factory;
use Joomla\CMS\Language\Text;
use Joomla\CMS\Plugin\PluginHelper;
use Joomla\CMS\Uri\Uri;
use Joomla\Registry\Registry;

\defined('_JEXEC') or die;

$enum = fn($v, array $ok, $def = '') => in_array($v, $ok, true) ? $v : $def;

$cardClass = [
    'card' => 'uk-card uk-card-default uk-card-body',
    'primary' => 'uk-card uk-card-primary uk-card-body',
    'secondary' => 'uk-card uk-card-secondary uk-card-body',
    'plain' => 'wgb-plain',
][$props['card_style'] ?? 'card'] ?? 'uk-card uk-card-default uk-card-body';

$layout    = $enum($props['layout'] ?? '', ['inline', 'popup', 'slidein'], 'inline');
$slotCols  = (int) $enum((string) ($props['slot_columns'] ?? ''), ['2', '3', '4'], '3');
$slotStyle = $enum($props['slot_style'] ?? '', ['default', 'primary', 'secondary'], 'default');
$slotSize  = $enum($props['slot_size'] ?? '', ['small', 'large']);
$btnStyle  = $enum($props['button_style'] ?? '', ['default', 'primary', 'secondary'], 'primary');
$btnSize   = $enum($props['button_size'] ?? '', ['small', 'large']);
$accent    = preg_match('/^#[0-9a-fA-F]{3,8}$/', (string) ($props['accent_color'] ?? '')) ? $props['accent_color'] : '';

$title    = (string) ($props['title'] ?? '') !== '' ? $props['title'] : Text::_('PLG_SYSTEM_WEBGBOOKING_DEFAULT_TITLE');
$service  = (string) ($props['service'] ?? '');
$btnText  = (string) ($props['button_text'] ?? '') !== '' ? $props['button_text'] : Text::_('PLG_SYSTEM_WEBGBOOKING_DEFAULT_BUTTON');

$locale  = Factory::getApplication()->getLanguage()->getTag();
$density  = $enum($props['cal_density'] ?? '', ['compact', 'comfortable'], 'compact');
$calStyle = $enum($props['cal_style'] ?? '', ['calendly', 'plain'], 'calendly');
$headCol  = preg_match('/^#[0-9a-fA-F]{3,8}$/', (string) ($props['header_color'] ?? '')) ? $props['header_color'] : '';

// Availability comes from the plugin Options (backend); the widget builds real slots from it.
$pp = new Registry(PluginHelper::getPlugin('system', 'webgbooking')->params ?? '');
$timeOk = fn($v, $def) => preg_match('/^\d{1,2}:\d{2}$/', (string) $v) ? (string) $v : $def;
$wd = $pp->get('work_days', [1, 2, 3, 4, 5]);
$workDays = array_values(array_map('intval', is_array($wd) ? $wd : explode(',', (string) $wd)));

$cfg = [
    'locale'    => $locale,
    'cols'      => $slotCols,
    'slotStyle' => $slotStyle,
    'slotSize'  => $slotSize ? 'uk-button-' . $slotSize : '',
    'btnStyle'  => $btnStyle,
    'accent'    => $accent,
    'workStart' => $timeOk($pp->get('work_start', '09:00'), '09:00'),
    'workEnd'   => $timeOk($pp->get('work_end', '18:00'), '18:00'),
    'interval'  => (int) $pp->get('slot_interval', 30),
    'duration'  => (int) $pp->get('slot_duration', 30),
    'workDays'  => $workDays,
    'minNotice' => (int) $pp->get('min_notice', 2),
    'window'    => (int) $pp->get('window_days', 30),
    'ajaxUrl'   => Uri::root(true) . '/index.php?option=com_ajax&group=system&plugin=webgbooking&format=json',
    'labels'    => [
        'stepDay'     => Text::_('PLG_SYSTEM_WEBGBOOKING_STEP_DAY'),
        'stepTime'    => Text::_('PLG_SYSTEM_WEBGBOOKING_STEP_TIME'),
        'stepDetails' => Text::_('PLG_SYSTEM_WEBGBOOKING_STEP_DETAILS'),
        'back'        => Text::_('PLG_SYSTEM_WEBGBOOKING_BACK'),
        'noSlots'     => Text::_('PLG_SYSTEM_WEBGBOOKING_NO_SLOTS'),
        'fName'       => Text::_('PLG_SYSTEM_WEBGBOOKING_FIELD_NAME'),
        'fEmail'      => Text::_('PLG_SYSTEM_WEBGBOOKING_FIELD_EMAIL'),
        'fPhone'      => Text::_('PLG_SYSTEM_WEBGBOOKING_FIELD_PHONE'),
        'fNotes'      => Text::_('PLG_SYSTEM_WEBGBOOKING_FIELD_NOTES'),
        'privacy'     => Text::_('PLG_SYSTEM_WEBGBOOKING_PRIVACY'),
        'bookNow'     => Text::_('PLG_SYSTEM_WEBGBOOKING_BOOK_NOW'),
        'required'    => Text::_('PLG_SYSTEM_WEBGBOOKING_REQUIRED'),
        'demo'        => Text::_('PLG_SYSTEM_WEBGBOOKING_DEMO_NOTE'),
        'done'        => Text::_('PLG_SYSTEM_WEBGBOOKING_DONE'),
        'sending'     => Text::_('PLG_SYSTEM_WEBGBOOKING_SENDING'),
        'bookErr'     => Text::_('PLG_SYSTEM_WEBGBOOKING_BOOK_ERR'),
    ],
];

$cfgAttr = htmlspecialchars(json_encode($cfg, JSON_UNESCAPED_UNICODE), ENT_QUOTES, 'UTF-8');
$vars = [];
if ($accent) { $vars[] = '--wgb-accent:' . $accent; }
if ($headCol) { $vars[] = '--wgb-head:' . $headCol; $vars[] = '--wgb-head-op:1'; }
$styleVar = $vars ? ' style="' . htmlspecialchars(implode(';', $vars), ENT_QUOTES, 'UTF-8') . '"' : '';
$uid = 'wgb-' . substr(md5(uniqid('', true)), 0, 8);

$root = $this->el('div', ['class' => ['wgb-booking', 'wgb-' . $density, 'wgb-style-' . $calStyle, ($accent ? 'wgb-has-accent' : ''), $cardClass]]);

$inner = function () use ($title, $service, $cfg) {
    ob_start(); ?>
    <?php if ($title !== '') : ?><h3 class="uk-card-title uk-margin-remove-bottom wgb-title"><?= htmlspecialchars($title, ENT_QUOTES, 'UTF-8') ?></h3><?php endif ?>
    <?php if ($service !== '') : ?><div class="uk-text-meta uk-margin-small-bottom"><?= htmlspecialchars($service, ENT_QUOTES, 'UTF-8') ?></div><?php endif ?>
    <div class="wgb-mount uk-margin-small-top"><div class="uk-text-meta"><?= htmlspecialchars($cfg['labels']['stepDay'], ENT_QUOTES, 'UTF-8') ?>…</div></div>
    <?php return ob_get_clean();
};

$trigBtn = trim('uk-button uk-button-' . $btnStyle . ($btnSize ? ' uk-button-' . $btnSize : '') . (!empty($props['button_fullwidth']) ? ' uk-width-1-1' : ''));

?>
<?php if ($layout === 'inline') : ?>

    <?= $root($props, $attrs) ?>
    <div data-wgb="<?= $cfgAttr ?>"<?= $styleVar ?>><?= $inner() ?></div>
    </div>

<?php else : ?>

    <?php $trig = $this->el('div', ['class' => ['wgb-booking-trigger']]); ?>
    <?= $trig($props, $attrs) ?>
        <button class="<?= $trigBtn ?>" type="button" uk-toggle="target: #<?= $uid ?>"<?= $styleVar ?>><?= htmlspecialchars($btnText, ENT_QUOTES, 'UTF-8') ?></button>
        <?php if ($layout === 'popup') : ?>
        <div id="<?= $uid ?>" uk-modal>
            <div class="uk-modal-dialog uk-modal-body uk-margin-auto-vertical">
                <button class="uk-modal-close-default" type="button" uk-close></button>
                <div class="wgb-booking wgb-<?= $density ?> wgb-style-<?= $calStyle ?><?= $accent ? ' wgb-has-accent' : '' ?>" data-wgb="<?= $cfgAttr ?>"<?= $styleVar ?>><?= $inner() ?></div>
            </div>
        </div>
        <?php else : ?>
        <div id="<?= $uid ?>" uk-offcanvas="flip: true; overlay: true">
            <div class="uk-offcanvas-bar">
                <button class="uk-offcanvas-close" type="button" uk-close></button>
                <div class="wgb-booking wgb-<?= $density ?> wgb-style-<?= $calStyle ?><?= $accent ? ' wgb-has-accent' : '' ?>" data-wgb="<?= $cfgAttr ?>"<?= $styleVar ?>><?= $inner() ?></div>
            </div>
        </div>
        <?php endif ?>
    </div>

<?php endif ?>

<style>
/* The interactive area sits on its own readable surface, regardless of the section colour behind. */
.wgb-booking .wgb-mount{background:#fff;color:#2b2b2b;border-radius:8px;padding:14px}
.wgb-booking .wgb-mount .uk-text-meta,.wgb-booking .wgb-mount .uk-text-muted{color:#6a6a6a}
.wgb-booking .wgb-cal-nav{display:flex;align-items:center;justify-content:space-between;margin-bottom:8px}
.wgb-booking .wgb-cal-head,.wgb-booking .wgb-cal-grid{display:grid;grid-template-columns:repeat(7,1fr);gap:5px}
.wgb-booking .wgb-cal-head span{text-align:center;font-size:10px;line-height:1.4;text-transform:uppercase;color:var(--wgb-head,inherit);opacity:var(--wgb-head-op,.55)}
/* High-contrast cells on the white panel: dark numbers always readable; accent only on the selected day */
.wgb-booking .wgb-cell{position:relative;display:flex;align-items:center;justify-content:center;min-height:36px;padding:0;border:1px solid transparent;border-radius:8px;cursor:pointer;font-size:14px;font-weight:600;line-height:1;background:transparent;color:#2b2b2b}
/* Available day = a clear white box with a defined border and a dark, readable number */
.wgb-booking .wgb-cell.wgb-avail{background:#fff;border-color:#ccd3da;color:#1f2937}
.wgb-booking .wgb-cell.wgb-avail:hover{background:#eef1f5;border-color:#94a3b4}
.wgb-booking .wgb-cell:disabled{background:transparent;border-color:transparent;color:#c2c7ce;font-weight:400;cursor:default}
.wgb-booking .wgb-blank{background:transparent;border-color:transparent}
.wgb-booking .wgb-cell.wgb-active{background:var(--wgb-accent,#2b2b2b);border-color:var(--wgb-accent,#2b2b2b);color:#fff}
.wgb-booking .wgb-cell.wgb-today::after{content:"";position:absolute;bottom:5px;left:50%;transform:translateX(-50%);width:4px;height:4px;border-radius:50%;background:currentColor}
.wgb-booking.wgb-comfortable .wgb-cell{min-height:44px;font-size:15px}
.wgb-booking.wgb-comfortable .wgb-cal-head span{font-size:11px}
/* Plain variant: squared boxes */
.wgb-booking.wgb-style-plain .wgb-cell.wgb-avail{border-radius:4px}
/* Optional accent override (only when the element sets a colour) */
.wgb-booking.wgb-has-accent .wgb-cell.wgb-active{background:var(--wgb-accent)!important;border-color:var(--wgb-accent)!important;color:#fff!important}
.wgb-booking .wgb-summary{cursor:pointer}
</style>
<script>
(function(){
  if (window.__wgbInit){ window.__wgbInit(); return; }
  function startToday(){var d=new Date();d.setHours(0,0,0,0);return d;}
  function sameDay(a,b){return a&&b&&a.getFullYear()==b.getFullYear()&&a.getMonth()==b.getMonth()&&a.getDate()==b.getDate();}
  function fmtFull(d,loc){try{return d.toLocaleDateString(loc,{weekday:'long',day:'numeric',month:'long'});}catch(e){return (d.getMonth()+1)+'/'+d.getDate();}}
  function monthLabel(d,loc){try{return d.toLocaleDateString(loc,{month:'long',year:'numeric'});}catch(e){return (d.getMonth()+1)+' '+d.getFullYear();}}
  function weekdays(loc){var b=new Date(2024,0,1),a=[];for(var i=0;i<7;i++){var d=new Date(b);d.setDate(b.getDate()+i);try{a.push(d.toLocaleDateString(loc,{weekday:'short'}));}catch(e){a.push(['Mo','Tu','We','Th','Fr','Sa','Su'][i]);}}return a;}
  function esc(s){var e=document.createElement('span');e.textContent=(s==null?'':s);return e.innerHTML;}
  function emailOk(v){return /^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(v);}
  function render(root){
    var cfg={};try{cfg=JSON.parse(root.getAttribute('data-wgb')||'{}');}catch(e){}
    var L=cfg.labels||{},loc=cfg.locale||'en',cols=cfg.cols||3;
    var slotBtn='uk-button uk-button-'+(cfg.slotStyle||'default')+(cfg.slotSize?' '+cfg.slotSize:'');
    var mount=root.querySelector('.wgb-mount')||root;
    var st={view:startToday(),date:null,time:null,form:{name:'',email:'',phone:'',notes:'',privacy:false},err:false,step:'cal'};
    function avail(d){var t=startToday();if(d<t)return false;var max=new Date(t);max.setDate(max.getDate()+(cfg.window||30));if(d>max)return false;var wd=(cfg.workDays&&cfg.workDays.length)?cfg.workDays:[1,2,3,4,5];if(wd.indexOf(d.getDay())===-1)return false;return slots(d).length>0;}
    function genTimes(d){var ws=(cfg.workStart||'09:00').split(':'),we=(cfg.workEnd||'18:00').split(':');var s=(+ws[0])*60+(+ws[1]),e=(+we[0])*60+(+we[1]),iv=cfg.interval||30,dur=cfg.duration||iv;var out=[],now=new Date(),notice=(cfg.minNotice||0)*3600000;for(var m=s;m+dur<=e;m+=iv){var hh=Math.floor(m/60),mm=m%60;var dt=new Date(d);dt.setHours(hh,mm,0,0);if(dt-now<notice)continue;out.push((hh<10?'0':'')+hh+':'+(mm<10?'0':'')+mm);}return out;}
    function slots(d){return genTimes(d);}
    function fetchSlots(d,cb){st.daySlots=null;var url=cfg.ajaxUrl||'';if(!url){st.daySlots=genTimes(d);cb();return;}fetch(url+'&action=slots&date='+isoDate(d),{headers:{'X-Requested-With':'XMLHttpRequest'},credentials:'same-origin'}).then(function(r){return r.json();}).then(function(res){st.daySlots=(res&&res.slots)?res.slots:genTimes(d);cb();}).catch(function(){st.daySlots=genTimes(d);cb();});}
    function pad2(n){return (n<10?'0':'')+n;}
    function isoDate(d){return d.getFullYear()+'-'+pad2(d.getMonth()+1)+'-'+pad2(d.getDate());}
    function errBox(msg){return '<div class="uk-alert uk-alert-danger" uk-alert>'+esc(msg)+'</div><button type="button" class="uk-button uk-button-default uk-button-small uk-margin-small-top" data-act="retry">'+esc(L.back)+'</button>';}
    function submit(){
      mount.innerHTML='<div class="uk-text-center uk-padding-small"><span uk-spinner></span> '+esc(L.sending||'')+'</div>';
      var url=cfg.ajaxUrl||'';
      fetch(url+'&action=token',{headers:{'X-Requested-With':'XMLHttpRequest'},credentials:'same-origin'})
        .then(function(r){return r.json();})
        .then(function(tk){
          var fd=new FormData();fd.append('action','book');if(tk&&tk.token)fd.append(tk.token,'1');
          fd.append('date',isoDate(st.date));fd.append('time',st.time||'');
          fd.append('name',st.form.name);fd.append('email',st.form.email);
          fd.append('phone',st.form.phone);fd.append('notes',st.form.notes);
          fd.append('privacy',st.form.privacy?'1':'0');fd.append('website',st.form.website||'');
          return fetch(url+'&action=book',{method:'POST',body:fd,headers:{'X-Requested-With':'XMLHttpRequest'},credentials:'same-origin'});
        })
        .then(function(r){return r.json();})
        .then(function(res){
          if(res&&res.ok){mount.innerHTML='<div class="uk-alert uk-alert-success" uk-alert>'+esc(res.message||L.done)+'</div>';}
          else{mount.innerHTML=errBox((res&&res.message)||L.bookErr);}
        })
        .catch(function(){mount.innerHTML=errBox(L.bookErr);});
    }
    function monthGrid(v){var y=v.getFullYear(),m=v.getMonth();var fday=(new Date(y,m,1).getDay()+6)%7;var dim=new Date(y,m+1,0).getDate();var c=[];for(var i=0;i<fday;i++)c.push(null);for(var d=1;d<=dim;d++)c.push(new Date(y,m,d));return c;}
    function summary(){
      var h='<div class="wgb-summary uk-text-meta uk-margin-small-bottom" data-act="back"><span uk-icon="icon:chevron-left;ratio:0.8"></span> ';
      h+='<b>'+esc(fmtFull(st.date,loc))+'</b>'+(st.time?(' · <b>'+esc(st.time)+'</b>'):'')+'</div>';
      return h;
    }
    function draw(){
      var h=[];
      if(st.step==='cal'){
        h.push('<div class="wgb-cal-nav"><button class="uk-icon-button" uk-icon="chevron-left" type="button" data-act="prev"></button><strong>'+esc(monthLabel(st.view,loc))+'</strong><button class="uk-icon-button" uk-icon="chevron-right" type="button" data-act="next"></button></div>');
        h.push('<div class="wgb-cal-head">'+weekdays(loc).map(function(w){return '<span>'+esc(w)+'</span>';}).join('')+'</div>');
        var cells=monthGrid(st.view).map(function(d){
          if(!d)return '<span class="wgb-cell wgb-blank"></span>';
          var ok=avail(d),sel=sameDay(d,st.date),cls='wgb-cell'+(sameDay(d,startToday())?' wgb-today':'');
          if(sel){cls+=' wgb-active';}else if(ok){cls+=' wgb-avail';}
          return '<button type="button" class="'+cls+'" data-date="'+isoDate(d)+'"'+(ok?'':' disabled')+'>'+d.getDate()+'</button>';
        });
        h.push('<div class="wgb-cal-grid">'+cells.join('')+'</div>');
      } else if(st.step==='time'){
        h.push(summary());
        h.push('<div class="uk-text-meta uk-text-bold uk-margin-small-bottom">'+esc(L.stepTime)+'</div>');
        if(st.loadingBusy){h.push('<div class="uk-text-center uk-padding-small"><span uk-spinner></span></div>');}
        else{var sl=st.daySlots||[];
        if(!sl.length)h.push('<div class="uk-alert uk-alert-warning" uk-alert>'+esc(L.noSlots)+'</div>');
        else h.push('<div style="display:grid;grid-template-columns:repeat('+cols+',1fr);gap:6px">'+sl.map(function(t){return '<button type="button" class="'+slotBtn+' wgb-slot" data-time="'+esc(t)+'">'+esc(t)+'</button>';}).join('')+'</div>');}
      } else if(st.step==='form'){
        h.push(summary());
        h.push('<div class="uk-text-meta uk-text-bold uk-margin-small-bottom">'+esc(L.stepDetails)+'</div>');
        if(st.err)h.push('<div class="uk-alert uk-alert-danger" uk-alert>'+esc(L.required)+'</div>');
        h.push('<div class="uk-margin-small"><input class="uk-input wgb-f-name" type="text" placeholder="'+esc(L.fName)+' *" value="'+esc(st.form.name)+'"></div>');
        h.push('<div class="uk-margin-small"><input class="uk-input wgb-f-email" type="email" placeholder="'+esc(L.fEmail)+' *" value="'+esc(st.form.email)+'"></div>');
        h.push('<div class="uk-margin-small"><input class="uk-input wgb-f-phone" type="tel" placeholder="'+esc(L.fPhone)+'" value="'+esc(st.form.phone)+'"></div>');
        h.push('<div class="uk-margin-small"><textarea class="uk-textarea wgb-f-notes" rows="2" placeholder="'+esc(L.fNotes)+'">'+esc(st.form.notes)+'</textarea></div>');
        h.push('<label class="uk-margin-small uk-display-block"><input class="uk-checkbox wgb-f-privacy" type="checkbox"'+(st.form.privacy?' checked':'')+'> '+esc(L.privacy)+' *</label>');
        h.push('<input type="text" class="wgb-f-website" name="website" tabindex="-1" autocomplete="off" aria-hidden="true" style="position:absolute!important;left:-9999px;top:-9999px;height:1px;width:1px;opacity:0">');
        h.push('<button type="button" class="uk-button uk-button-'+(cfg.btnStyle||'primary')+' uk-width-1-1 uk-margin-small-top" data-act="book">'+esc(L.bookNow)+'</button>');
      } else {
        h.push('<div class="uk-alert uk-alert-success" uk-alert>'+esc(L.done)+'</div>');
      }
      mount.innerHTML=h.join('');
    }
    function readForm(){var q=function(c){var n=mount.querySelector('.'+c);return n?n.value:'';};st.form={name:q('wgb-f-name').trim(),email:q('wgb-f-email').trim(),phone:q('wgb-f-phone').trim(),notes:q('wgb-f-notes').trim(),website:q('wgb-f-website'),privacy:!!(mount.querySelector('.wgb-f-privacy')||{}).checked};}
    mount.addEventListener('click',function(e){
      var t=e.target.closest('[data-act],[data-date],[data-time]');if(!t)return;
      if(t.hasAttribute('data-date')){st.date=new Date(t.getAttribute('data-date')+'T00:00:00');st.time=null;st.step='time';st.loadingBusy=true;st.daySlots=null;draw();fetchSlots(st.date,function(){st.loadingBusy=false;draw();});return;}
      else if(t.hasAttribute('data-time')){st.time=t.getAttribute('data-time');st.step='form';st.err=false;}
      else{var a=t.getAttribute('data-act');
        if(a==='prev')st.view=new Date(st.view.getFullYear(),st.view.getMonth()-1,1);
        else if(a==='next')st.view=new Date(st.view.getFullYear(),st.view.getMonth()+1,1);
        else if(a==='back'){if(st.step==='form'){readForm();st.time=null;st.step='time';}else{st.date=null;st.step='cal';}}
        else if(a==='book'){readForm();if(!st.form.name||!emailOk(st.form.email)||!st.form.privacy){st.err=true;}else{submit();return;}}
        else if(a==='retry'){st.err=false;st.step='form';}
      }
      draw();
    });
    draw();
  }
  window.__wgbInit=function(){
    Array.prototype.forEach.call(document.querySelectorAll('[data-wgb]'),function(root){
      if(root.getAttribute('data-wgb-ready'))return;root.setAttribute('data-wgb-ready','1');
      if(!root.querySelector('.wgb-mount')){var m=document.createElement('div');m.className='wgb-mount';root.appendChild(m);}
      render(root);
    });
  };
  if(document.readyState!=='loading')window.__wgbInit();else document.addEventListener('DOMContentLoaded',window.__wgbInit);
})();
</script>
