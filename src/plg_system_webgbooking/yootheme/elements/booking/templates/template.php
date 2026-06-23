<?php

/**
 * WebG Booking element — render template (UIkit markup + interactive widget).
 * YOOtheme contract: $this->el(), $props, $attrs, $children, $builder.
 *
 * The widget is a JS step wizard (day -> time -> confirm) that advances in place.
 * Availability is demo data for now; the real data comes from the com_webgbooking
 * engine. All visitor-facing strings are translatable (Joomla language, IT/EN shipped).
 *
 * @license GNU General Public License version 2 or later
 */

use Joomla\CMS\Factory;
use Joomla\CMS\Language\Text;

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

$locale = Factory::getApplication()->getLanguage()->getTag();

// Config handed to the widget JS (already localised — escaped into a data attribute).
$cfg = [
    'locale'    => $locale,
    'cols'      => $slotCols,
    'slotStyle' => $slotStyle,
    'slotSize'  => $slotSize ? 'uk-button-' . $slotSize : '',
    'btnStyle'  => $btnStyle,
    'btnSize'   => $btnSize ? 'uk-button-' . $btnSize : '',
    'accent'    => $accent,
    'labels'    => [
        'stepDay'   => Text::_('PLG_SYSTEM_WEBGBOOKING_STEP_DAY'),
        'stepTime'  => Text::_('PLG_SYSTEM_WEBGBOOKING_STEP_TIME'),
        'back'      => Text::_('PLG_SYSTEM_WEBGBOOKING_BACK'),
        'change'    => Text::_('PLG_SYSTEM_WEBGBOOKING_CHANGE'),
        'noSlots'   => Text::_('PLG_SYSTEM_WEBGBOOKING_NO_SLOTS'),
        'confirm'   => Text::_('PLG_SYSTEM_WEBGBOOKING_CONFIRM_BTN'),
        'prev'      => Text::_('PLG_SYSTEM_WEBGBOOKING_PREV'),
        'next'      => Text::_('PLG_SYSTEM_WEBGBOOKING_NEXT'),
        'day'       => Text::_('PLG_SYSTEM_WEBGBOOKING_SELECTED_DAY'),
        'time'      => Text::_('PLG_SYSTEM_WEBGBOOKING_SELECTED_TIME'),
        'demo'      => Text::_('PLG_SYSTEM_WEBGBOOKING_DEMO_NOTE'),
        'done'      => Text::_('PLG_SYSTEM_WEBGBOOKING_DONE'),
    ],
];

$cfgAttr  = htmlspecialchars(json_encode($cfg, JSON_UNESCAPED_UNICODE), ENT_QUOTES, 'UTF-8');
$styleVar = $accent ? ' style="--wgb-accent:' . htmlspecialchars($accent, ENT_QUOTES, 'UTF-8') . '"' : '';
$uid      = 'wgb-' . substr(md5(uniqid('', true)), 0, 8);

$root = $this->el('div', ['class' => ['wgb-booking', $cardClass]]);

// Server-rendered fallback (also what the builder preview shows before JS runs).
$widgetHtml = function () use ($title, $service, $cfg) {
    ob_start(); ?>
    <?php if ($title !== '') : ?><h3 class="uk-card-title uk-margin-remove-bottom wgb-title"><?= htmlspecialchars($title, ENT_QUOTES, 'UTF-8') ?></h3><?php endif ?>
    <?php if ($service !== '') : ?><div class="uk-text-meta uk-margin-small-bottom"><?= htmlspecialchars($service, ENT_QUOTES, 'UTF-8') ?></div><?php endif ?>
    <div class="wgb-mount uk-margin-small-top"><div class="uk-text-meta"><?= htmlspecialchars($cfg['labels']['stepDay'], ENT_QUOTES, 'UTF-8') ?>…</div></div>
    <?php return ob_get_clean();
};

?>
<?php if ($layout === 'inline') : ?>

    <?= $root($props, $attrs) ?>
    <div data-wgb="<?= $cfgAttr ?>"<?= $styleVar ?>><?= $widgetHtml() ?></div>
    </div>

<?php else : ?>

    <?php
    $trigEl = $this->el('div', ['class' => ['wgb-booking-trigger']]);
    $mainBtn = trim('uk-button uk-button-' . $btnStyle . ($btnSize ? ' uk-button-' . $btnSize : '') . (!empty($props['button_fullwidth']) ? ' uk-width-1-1' : ''));
    ?>
    <?= $trigEl($props, $attrs) ?>
        <?php if ($layout === 'popup') : ?>
        <button class="<?= $mainBtn ?>" type="button" uk-toggle="target: #<?= $uid ?>"<?= $styleVar ?>><?= htmlspecialchars($btnText, ENT_QUOTES, 'UTF-8') ?></button>
        <div id="<?= $uid ?>" uk-modal>
            <div class="uk-modal-dialog uk-modal-body uk-margin-auto-vertical">
                <button class="uk-modal-close-default" type="button" uk-close></button>
                <div class="wgb-booking" data-wgb="<?= $cfgAttr ?>"<?= $styleVar ?>><?= $widgetHtml() ?></div>
            </div>
        </div>
        <?php else : ?>
        <button class="<?= $mainBtn ?>" type="button" uk-toggle="target: #<?= $uid ?>"<?= $styleVar ?>><?= htmlspecialchars($btnText, ENT_QUOTES, 'UTF-8') ?></button>
        <div id="<?= $uid ?>" uk-offcanvas="flip: true; overlay: true">
            <div class="uk-offcanvas-bar">
                <button class="uk-offcanvas-close" type="button" uk-close></button>
                <div class="wgb-booking" data-wgb="<?= $cfgAttr ?>"<?= $styleVar ?>><?= $widgetHtml() ?></div>
            </div>
        </div>
        <?php endif ?>
    </div>

<?php endif ?>

<style>
.wgb-booking .wgb-day.wgb-active,.wgb-booking .wgb-slot.wgb-active{background-color:var(--wgb-accent,#1e87f0);border-color:var(--wgb-accent,#1e87f0);color:#fff}
.wgb-booking .wgb-confirm{background-color:var(--wgb-accent,#1e87f0);border-color:var(--wgb-accent,#1e87f0);color:#fff}
.wgb-days{display:flex;gap:6px;overflow-x:auto;-webkit-overflow-scrolling:touch}
.wgb-days .wgb-day{flex:1 0 auto;min-width:74px}
.wgb-summary{cursor:pointer}
@media (max-width:639px){.wgb-slots{grid-template-columns:repeat(2,1fr)!important}}
</style>
<script>
(function(){
  if (window.__wgbInit){ window.__wgbInit(); return; }
  function pad(n){return (n<10?'0':'')+n;}
  function startToday(){var d=new Date();d.setHours(0,0,0,0);return d;}
  function addDays(d,n){var x=new Date(d);x.setDate(x.getDate()+n);return x;}
  function fmt(d,loc){try{return d.toLocaleDateString(loc,{weekday:'short',day:'numeric',month:'short'});}catch(e){return (d.getMonth()+1)+'/'+d.getDate();}}
  function slots(d){var wd=d.getDay();if(wd===0||wd===6)return [];return ['09:00','09:30','10:00','11:00','14:00','15:00','16:00','17:00'];}
  function esc(s){var e=document.createElement('span');e.textContent=s;return e.innerHTML;}
  function render(root){
    var cfg={};try{cfg=JSON.parse(root.getAttribute('data-wgb')||'{}');}catch(e){}
    var L=cfg.labels||{}, loc=cfg.locale||'en', cols=cfg.cols||3;
    var slotBtn='uk-button uk-button-'+(cfg.slotStyle||'default')+(cfg.slotSize?' '+cfg.slotSize:'');
    var mount=root.querySelector('.wgb-mount')||root;
    var st={weekStart:startToday(),day:null,time:null};
    function week(){var a=[];for(var i=0;i<7;i++)a.push(addDays(st.weekStart,i));return a;}
    function setStep(){
      var titleEl=root.querySelector('.wgb-title');
      var h=[];
      // breadcrumb
      if(st.day){h.push('<div class="wgb-summary uk-text-meta uk-margin-small-bottom" data-act="reset"><span uk-icon="icon:chevron-left;ratio:0.8"></span> '+esc(L.day||'Day')+': <b>'+esc(fmt(st.day,loc))+'</b>'+(st.time?(' · '+esc(L.time||'Time')+': <b>'+esc(st.time)+'</b>'):'')+' — '+esc(L.change||'change')+'</div>');}
      if(!st.day){
        h.push('<div class="uk-flex uk-flex-between uk-flex-middle uk-margin-small-bottom"><span class="uk-text-meta uk-text-bold">'+esc(L.stepDay||'Choose a day')+'</span><span><button class="uk-icon-button" uk-icon="chevron-left" type="button" data-act="prev"></button> <button class="uk-icon-button" uk-icon="chevron-right" type="button" data-act="next"></button></span></div>');
        h.push('<div class="wgb-days">'+week().map(function(d){var has=slots(d).length>0;return '<button type="button" class="uk-button uk-button-default wgb-day" data-day="'+d.toISOString()+'"'+(has?'':' disabled')+'>'+esc(fmt(d,loc))+'</button>';}).join('')+'</div>');
      } else if(st.day && !st.time){
        h.push('<div class="uk-text-meta uk-text-bold uk-margin-small-bottom">'+esc(L.stepTime||'Choose a time')+'</div>');
        var sl=slots(st.day);
        if(!sl.length){h.push('<div class="uk-alert uk-alert-warning" uk-alert>'+esc(L.noSlots||'No availability')+'</div>');}
        else{h.push('<div class="wgb-slots" style="display:grid;grid-template-columns:repeat('+cols+',1fr);gap:6px">'+sl.map(function(t){return '<button type="button" class="'+slotBtn+' wgb-slot" data-time="'+esc(t)+'">'+esc(t)+'</button>';}).join('')+'</div>');}
      } else {
        h.push('<div class="uk-text-meta uk-text-bold uk-margin-small-bottom">'+esc(L.confirm||'Confirm')+'</div>');
        h.push('<button type="button" class="uk-button wgb-confirm uk-width-1-1" data-act="confirm">'+esc(L.confirm||'Confirm booking')+'</button>');
      }
      h.push('<div class="uk-text-meta uk-text-muted uk-margin-small-top">'+esc(L.demo||'')+'</div>');
      mount.innerHTML=h.join('');
    }
    mount.addEventListener('click',function(e){
      var t=e.target.closest('[data-act],[data-day],[data-time]'); if(!t)return;
      if(t.hasAttribute('data-day')){st.day=new Date(t.getAttribute('data-day'));st.time=null;}
      else if(t.hasAttribute('data-time')){st.time=t.getAttribute('data-time');}
      else{var a=t.getAttribute('data-act');
        if(a==='prev')st.weekStart=addDays(st.weekStart,-7);
        else if(a==='next')st.weekStart=addDays(st.weekStart,7);
        else if(a==='reset'){st.day=null;st.time=null;}
        else if(a==='confirm'){mount.innerHTML='<div class="uk-alert uk-alert-success" uk-alert>'+esc(L.done||'Booked!')+'</div>';return;}
      }
      setStep();
    });
    setStep();
  }
  window.__wgbInit=function(){
    Array.prototype.forEach.call(document.querySelectorAll('[data-wgb]'),function(root){
      if(root.getAttribute('data-wgb-ready'))return; root.setAttribute('data-wgb-ready','1');
      if(!root.querySelector('.wgb-mount')){var m=document.createElement('div');m.className='wgb-mount';root.appendChild(m);}
      render(root);
    });
  };
  if(document.readyState!=='loading')window.__wgbInit();else document.addEventListener('DOMContentLoaded',window.__wgbInit);
})();
</script>
