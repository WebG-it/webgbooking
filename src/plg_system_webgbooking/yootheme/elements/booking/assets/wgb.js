(function(){
  if(window.__wgbBooted)return;window.__wgbBooted=1;
  function startToday(){var d=new Date();d.setHours(0,0,0,0);return d;}
  function sameDay(a,b){return a&&b&&a.getFullYear()==b.getFullYear()&&a.getMonth()==b.getMonth()&&a.getDate()==b.getDate();}
  function fmtFull(d,loc){try{return d.toLocaleDateString(loc,{weekday:'long',day:'numeric',month:'long'});}catch(e){return (d.getMonth()+1)+'/'+d.getDate();}}
  function monthLabel(d,loc){try{return d.toLocaleDateString(loc,{month:'long',year:'numeric'});}catch(e){return (d.getMonth()+1)+' '+d.getFullYear();}}
  function weekdays(loc){var b=new Date(2024,0,1),a=[];for(var i=0;i<7;i++){var d=new Date(b);d.setDate(b.getDate()+i);try{a.push(d.toLocaleDateString(loc,{weekday:'short'}));}catch(e){a.push(['Mo','Tu','We','Th','Fr','Sa','Su'][i]);}}return a;}
  /* ---- time zones: the server works in the SITE zone; the visitor sees (and books) in the zone chosen under the calendar ---- */
  var fmtCache={},wgbSeq=0;
  function tzOk(z){try{if(!z||typeof z!=='string')return false;new Intl.DateTimeFormat('en',{timeZone:z});return true;}catch(e){return false;}}
  function tzFmt(z){return fmtCache[z]||(fmtCache[z]=new Intl.DateTimeFormat('en-US',{timeZone:z,hourCycle:'h23',year:'numeric',month:'numeric',day:'numeric',hour:'numeric',minute:'numeric',second:'numeric'}));}
  function tzParts(ms,z){var p=tzFmt(z).formatToParts(new Date(ms)),o={};for(var i=0;i<p.length;i++){if(p[i].type!=='literal')o[p[i].type]=+p[i].value;}if(o.hour===24)o.hour=0;return o;}
  function tzOff(ms,z){var p=tzParts(ms,z);return Date.UTC(p.year,p.month-1,p.day,p.hour,p.minute,p.second)-Math.floor(ms/1000)*1000;}
  /* the instant (ms) of a wall-clock time in a zone, right across a change of the clock */
  function wallMs(y,mo,d,h,mi,z){var g=Date.UTC(y,mo-1,d,h,mi,0),o1=tzOff(g,z),r=g-o1,o2=tzOff(r,z);if(o2!==o1)r=g-o2;return r;}
  function pad2x(n){return (n<10?'0':'')+n;}
  var TZ_LIST=['Pacific/Honolulu','America/Anchorage','America/Los_Angeles','America/Denver','America/Chicago','America/New_York','America/Halifax','America/Sao_Paulo','Atlantic/Azores','Europe/London','Europe/Lisbon','Europe/Paris','Europe/Rome','Europe/Athens','Europe/Istanbul','Europe/Moscow','Africa/Cairo','Africa/Johannesburg','Africa/Nairobi','Asia/Dubai','Asia/Karachi','Asia/Kolkata','Asia/Dhaka','Asia/Bangkok','Asia/Shanghai','Asia/Singapore','Asia/Tokyo','Asia/Seoul','Australia/Perth','Australia/Sydney','Pacific/Auckland','UTC'];
  /* options of the zone menu: "<long name> (<current time there>)", one per distinct (name, clock rules); the zone in use is always the one that stands for its group */
  function tzOptions(loc,first){
    var ids=[];try{if(typeof Intl.supportedValuesOf==='function')ids=Intl.supportedValuesOf('timeZone');}catch(e){}
    if(!ids||!ids.length)ids=TZ_LIST.slice();
    var pri=[];for(var i=0;i<first.length;i++){if(first[i]&&tzOk(first[i])&&pri.indexOf(first[i])<0)pri.push(first[i]);}
    var all=pri.concat(ids.filter(function(z){return pri.indexOf(z)<0;})),now=Date.now(),seen={},out=[];
    for(var j=0;j<all.length;j++){var z=all[j];if(!tzOk(z))continue;
      var nm='';try{var pp=new Intl.DateTimeFormat(loc,{timeZone:z,timeZoneName:'long'}).formatToParts(new Date(now));for(var k=0;k<pp.length;k++){if(pp[k].type==='timeZoneName')nm=pp[k].value;}}catch(e){}
      if(!nm)nm=z.replace(/_/g,' ');
      var offs=[0,90,180,270].map(function(dd){return tzOff(now+dd*86400000,z)/60000;}),key=nm+'|'+offs.join('|');
      if(seen[key])continue;seen[key]=1;
      var tp=tzParts(now,z);out.push({id:z,name:nm,off:offs[0],t:pad2x(tp.hour)+':'+pad2x(tp.minute)});}
    out.sort(function(a,b){return a.off-b.off||(a.name<b.name?-1:a.name>b.name?1:0);});
    return out;
  }
  function esc(s){var e=document.createElement('span');e.textContent=(s==null?'':s);return e.innerHTML;}
  function emailOk(v){return /^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(v);}
  function render(root){
    var cfg={};try{cfg=JSON.parse(root.getAttribute('data-wgb')||'{}');}catch(e){}
    var L=cfg.labels||{},loc=cfg.locale||'en',cols=cfg.cols||3;
    var slotBtn='uk-button uk-button-'+(cfg.slotStyle||'default')+(cfg.slotSize?' '+cfg.slotSize:'');
    var mount=root.querySelector('.wgb-mount')||root;
    if(cfg.unavailable){mount.innerHTML='<div class="uk-alert uk-alert-warning" role="alert" uk-alert>'+esc(cfg.unavailable)+'</div>';return;}
    if(cfg.priceInvalid){mount.innerHTML='<div class="uk-alert uk-alert-danger" role="alert" uk-alert>'+esc(L.priceInvalid||'')+'</div>';return;}
    mount.setAttribute('role','region');mount.setAttribute('aria-live','polite');mount.setAttribute('aria-atomic','false');
    var st={view:startToday(),tz:'',date:null,time:null,form:{name:'',email:'',phone:'',notes:'',privacy:false,loc:'meet'},err:false,step:'cal'};
    var siteTz=(cfg.siteTz&&tzOk(cfg.siteTz))?cfg.siteTz:'',wgbId='wgb-tz-'+(++wgbSeq);
    var brTz='';try{brTz=Intl.DateTimeFormat().resolvedOptions().timeZone||'';}catch(e){}
    st.tz=siteTz?(tzOk(brTz)?brTz:siteTz):'';
    function conv(){return !!(siteTz&&st.tz&&st.tz!==siteTz);}
    function todayLabel(){if(!st.tz)return startToday();var p=tzParts(Date.now(),st.tz);return new Date(p.year,p.month-1,p.day);}
    if(st.tz)st.view=todayLabel();
    st.slotMap=st.slotMap||{};
    /* what the server needs to book: the date and time in the SITE zone (its zone), the instant, and the visitor's zone */
    function curSel(){var k=isoDate(st.date)+' '+st.time;if(conv()&&st.slotMap[k])return st.slotMap[k];var hm=(st.time||'').split(':'),ymd=isoDate(st.date).split('-');return {sd:isoDate(st.date),st:st.time||'',ms:siteTz?wallMs(+ymd[0],+ymd[1],+ymd[2],+hm[0],+hm[1],siteTz):0};}
    function convertDays(days){var out={};for(var sd in days){if(!Object.prototype.hasOwnProperty.call(days,sd))continue;var ts=days[sd],ymd=sd.split('-');if(!ts||!ts.length)continue;for(var i=0;i<ts.length;i++){var hm=String(ts[i]).split(':'),ms=wallMs(+ymd[0],+ymd[1],+ymd[2],+hm[0],+hm[1],siteTz),p=tzParts(ms,st.tz),vk=p.year+'-'+pad2x(p.month)+'-'+pad2x(p.day),vt=pad2x(p.hour)+':'+pad2x(p.minute);(out[vk]=out[vk]||[]).push(vt);st.slotMap[vk+' '+vt]={sd:sd,st:String(ts[i]),ms:ms};}}for(var k in out){if(Object.prototype.hasOwnProperty.call(out,k))out[k].sort();}return out;}
    function tzMenu(){
      if(!siteTz||!st.tz)return '';
      var opts=tzOptions(loc,[st.tz,siteTz,brTz]),cur=st.tz;
      return '<div class="wgb-tz uk-margin-small-top"><label class="uk-text-meta uk-display-block" for="'+wgbId+'">'+esc(L.tzLabel||'')+'</label><select id="'+wgbId+'" class="uk-select uk-form-small wgb-tz-sel">'+opts.map(function(o){return '<option value="'+esc(o.id)+'"'+(o.id===cur?' selected':'')+'>'+esc(o.name+' ('+o.t+')')+'</option>';}).join('')+'</select></div>';
    }
    function avail(d){return !!(st.monthDays&&st.monthDays[isoDate(d)]);}
    function genTimes(d){var ws=(cfg.workStart||'09:00').split(':'),we=(cfg.workEnd||'18:00').split(':');var s=(+ws[0])*60+(+ws[1]),e=(+we[0])*60+(+we[1]),iv=cfg.interval||30,dur=cfg.duration||iv;var out=[],now=new Date(),notice=(cfg.minNotice||0)*3600000;for(var m=s;m+dur<=e;m+=iv){var hh=Math.floor(m/60),mm=m%60;var dt=new Date(d);dt.setHours(hh,mm,0,0);if(dt-now<notice)continue;out.push((hh<10?'0':'')+hh+':'+(mm<10?'0':'')+mm);}return out;}
    function clientMonth(y,m){var o={},t=startToday(),max=new Date(t);max.setDate(max.getDate()+(cfg.window||30));var wd=(cfg.workDays&&cfg.workDays.length)?cfg.workDays:[1,2,3,4,5];var dim=new Date(y,m+1,0).getDate();for(var dd=1;dd<=dim;dd++){var dt=new Date(y,m,dd);if(dt<t||dt>max||wd.indexOf(dt.getDay())===-1)continue;var s=genTimes(dt);if(s.length)o[isoDate(dt)]=s;}return o;}
    function ensureMonth(){var key=st.view.getFullYear()+'-'+st.view.getMonth()+'|'+st.tz;if(st.monthKey===key||st.monthLoading===key||st.monthErr===key)return;st.calErr=null;st.monthLoading=key;st.monthDays={};var y=st.view.getFullYear(),m=st.view.getMonth(),url=cfg.ajaxUrl||'';var from=isoDate(new Date(y,m,1-(conv()?2:0))),to=isoDate(new Date(y,m+1,conv()?2:0));if(!url){st.monthDays=clientMonth(y,m);st.monthKey=key;st.monthLoading=null;return;}fetch(url+'&action=month&from='+from+'&to='+to+(cfg.ev?'&ev='+encodeURIComponent(cfg.ev):''),{headers:{'X-Requested-With':'XMLHttpRequest'},credentials:'same-origin'}).then(function(r){return r.json();}).then(function(res){if(res&&res.unavailable){st.monthDays={};st.calErr=res.message||L.calUnavailable||'';st.monthErr=key;st.monthLoading=null;draw();return;}st.monthDays=(res&&res.days&&typeof res.days==='object')?(conv()?convertDays(res.days):res.days):{};st.monthKey=key;st.monthLoading=null;draw();}).catch(function(){st.monthDays=clientMonth(y,m);st.monthKey=key;st.monthLoading=null;draw();});}
    function effLoc(){return cfg.location==='phone'?'phone':(cfg.location==='choice'?(st.form.loc==='phone'?'phone':'meet'):'meet');}
    function phoneOk(v){return /^[+()\d\s.\-\/]{6,60}$/.test(v)&&((v.match(/\d/g)||[]).length>=6);}
    function locLabel(){var l=effLoc();return l==='phone'?(L.locPhone||''):(L.locMeet||'');}
    function pad2(n){return (n<10?'0':'')+n;}
    function isoDate(d){return d.getFullYear()+'-'+pad2(d.getMonth()+1)+'-'+pad2(d.getDate());}
    function errBox(msg){return '<div class="uk-alert uk-alert-danger" role="alert" uk-alert>'+esc(msg)+'</div><button type="button" class="uk-button uk-button-default uk-button-small uk-margin-small-top" data-act="retry">'+esc(L.back)+'</button>';}
    function submit(){
      mount.innerHTML='<div class="uk-text-center uk-padding-small" role="status"><span uk-spinner></span> '+esc(L.sending||'')+'</div>';
      var url=cfg.ajaxUrl||'';
      fetch(url+'&action=token',{headers:{'X-Requested-With':'XMLHttpRequest'},credentials:'same-origin'})
        .then(function(r){return r.json();})
        .then(function(tk){
          var fd=new FormData();fd.append('action','book');if(tk&&tk.token)fd.append(tk.token,'1');
          var sel=curSel();fd.append('date',sel.sd);fd.append('time',sel.st);if(sel.ms)fd.append('ts',String(Math.floor(sel.ms/1000)));if(st.tz)fd.append('tz',st.tz);
          fd.append('name',st.form.name);fd.append('email',st.form.email);
          fd.append('phone',st.form.phone);fd.append('notes',st.form.notes);fd.append('guest',st.form.guest||'');
          fd.append('location',effLoc());fd.append('privacy',st.form.privacy?'1':'0');fd.append('website',st.form.website||'');fd.append('newsletter',st.form.news?'1':'0');fd.append('form',cfg.form||'');
          var cu=st.form.custom||{};for(var k in cu){if(Object.prototype.hasOwnProperty.call(cu,k))fd.append('custom['+k+']',cu[k]);}
          return fetch(url+'&action=book',{method:'POST',body:fd,headers:{'X-Requested-With':'XMLHttpRequest'},credentials:'same-origin'});
        })
        .then(function(r){return r.json();})
        .then(function(res){
          if(res&&res.ok&&res.redirect){try{window.dataLayer=window.dataLayer||[];window.dataLayer.push({event:'wgb_checkout_started'});}catch(e){}mount.innerHTML='<div class="uk-text-center uk-padding-small" role="status"><span uk-spinner></span> '+esc(L.payRedirecting||'')+'</div>';window.location.href=res.redirect;return;}
          if(res&&res.ok){track();mount.innerHTML='<div class="uk-alert uk-alert-success" role="alert" uk-alert>'+esc(res.message||L.done)+'</div>';goThanks();}
          else{mount.innerHTML=errBox((res&&res.message)||L.bookErr);}
        })
        .catch(function(){mount.innerHTML=errBox(L.bookErr);});
    }
    function goThanks(){if(!cfg.redirectUrl||cfg.mode==='reschedule')return;setTimeout(function(){try{window.location.href=cfg.redirectUrl;}catch(e){}},400);}
    function track(ev){try{var name=ev||cfg.analyticsEvent||'wgb_booking_completed';window.dataLayer=window.dataLayer||[];window.dataLayer.push({event:name});if(typeof window.gtag==='function'){window.gtag('event',name);}}catch(e){}}
    function submitReschedule(){
      mount.innerHTML='<div class="uk-text-center uk-padding-small" role="status"><span uk-spinner></span> '+esc(L.loading||L.sending||'')+'</div>';
      var url=cfg.ajaxUrl||'';var fd=new FormData();fd.append('action','reschedule');fd.append('token',cfg.token||'');var sel=curSel();fd.append('date',sel.sd);fd.append('time',sel.st);if(sel.ms)fd.append('ts',String(Math.floor(sel.ms/1000)));if(st.tz)fd.append('tz',st.tz);
      fetch(url+'&action=reschedule',{method:'POST',body:fd,headers:{'X-Requested-With':'XMLHttpRequest'},credentials:'same-origin'})
        .then(function(r){return r.json();})
        .then(function(res){if(res&&res.ok){track('wgb_reschedule');var hd=document.getElementById('wgb-m-date'),ht=document.getElementById('wgb-m-time');if(hd&&res.date)hd.textContent=res.date;if(ht&&res.time)ht.textContent=res.time;mount.innerHTML='<div class="uk-alert uk-alert-success" role="alert" uk-alert>'+esc(res.message||L.rsOk||'')+'</div>';}else{mount.innerHTML=errBox((res&&res.message)||L.bookErr);}})
        .catch(function(){mount.innerHTML=errBox(L.bookErr);});
    }
    function monthGrid(v){var y=v.getFullYear(),m=v.getMonth();var fday=(new Date(y,m,1).getDay()+6)%7;var dim=new Date(y,m+1,0).getDate();var c=[];for(var i=0;i<fday;i++)c.push(null);for(var d=1;d<=dim;d++)c.push(new Date(y,m,d));return c;}
    function summary(){
      var h='<button type="button" class="wgb-summary uk-text-meta uk-margin-small-bottom" data-act="back" aria-label="'+esc(L.back)+'"><span uk-icon="icon:chevron-left;ratio:0.8"></span> ';
      h+='<b>'+esc(fmtFull(st.date,loc))+'</b>'+(st.time?(' · <b>'+esc(st.time)+'</b>'):'')+'</button>';
      return h;
    }
    function cfHtml(){
      var defs=cfg.customFields||[];if(!defs.length)return '';
      var cv=st.form.custom||{};
      return defs.map(function(f){
        var v=cv[f.name]==null?'':cv[f.name];var ph=esc(f.label)+(f.required?' *':'');var star=f.required?' <span class="wgb-req">*</span>':'';
        var req=f.required?' aria-required="true"':'';
        if(f.type==='textarea')return '<div class="uk-margin-small"><textarea class="uk-textarea wgb-cf" data-cf="'+esc(f.name)+'" rows="3" aria-label="'+esc(f.label)+'"'+req+' placeholder="'+ph+'">'+esc(v)+'</textarea></div>';
        if(f.type==='select'){var opts='<option value="">'+esc(f.label)+(f.required?' *':'')+'</option>'+(f.options||[]).map(function(o){return '<option'+(o===v?' selected':'')+' value="'+esc(o)+'">'+esc(o)+'</option>';}).join('');return '<div class="uk-margin-small"><select class="uk-select wgb-cf" data-cf="'+esc(f.name)+'" aria-label="'+esc(f.label)+'"'+req+'>'+opts+'</select></div>';}
        if(f.type==='checkbox')return '<label class="uk-margin-small uk-display-block"><input class="uk-checkbox wgb-cf" data-cf="'+esc(f.name)+'" type="checkbox"'+(v==='1'||v===true?' checked':'')+req+'> '+esc(f.label)+star+'</label>';
        var itype=(f.type==='email'||f.type==='tel'||f.type==='number')?f.type:'text';
        return '<div class="uk-margin-small"><input class="uk-input wgb-cf" data-cf="'+esc(f.name)+'" type="'+itype+'" aria-label="'+esc(f.label)+'"'+req+' placeholder="'+ph+'" value="'+esc(v)+'"></div>';
      }).join('');
    }
    function cfMissing(){var defs=cfg.customFields||[],cv=st.form.custom||{};for(var i=0;i<defs.length;i++){if(!defs[i].required)continue;var val=cv[defs[i].name];if(defs[i].type==='checkbox'){if(val!=='1')return true;}else if(!val)return true;}return false;}
    function draw(){
      var h=[];
      if(st.step==='cal'){
        ensureMonth();
        h.push('<div class="wgb-cal-nav"><button class="uk-icon-button" uk-icon="chevron-left" type="button" data-act="prev" aria-label="'+esc(L.prevMonth)+'"></button><strong>'+esc(monthLabel(st.view,loc))+'</strong><button class="uk-icon-button" uk-icon="chevron-right" type="button" data-act="next" aria-label="'+esc(L.nextMonth)+'"></button></div>');
        if(st.monthLoading){h.push('<div class="uk-text-center uk-padding" role="status"><span uk-spinner></span> <span class="wgb-sr">'+esc(L.loading||'')+'</span></div>');}
        else if(st.calErr){
          h.push('<div class="uk-alert uk-alert-warning" role="alert" uk-alert>'+esc(st.calErr)+'</div><button type="button" class="uk-button uk-button-default uk-button-small" data-act="retrycal">'+esc(L.back||'')+'</button>');
        }
        else{
          h.push('<div class="wgb-cal-head">'+weekdays(loc).map(function(w){return '<span>'+esc(w)+'</span>';}).join('')+'</div>');
          var cells=monthGrid(st.view).map(function(d){
            if(!d)return '<span class="wgb-cell wgb-blank"></span>';
            var ok=avail(d),sel=sameDay(d,st.date),cls='wgb-cell'+(sameDay(d,todayLabel())?' wgb-today':'');
            if(sel){cls+=' wgb-active';}else if(ok){cls+=' wgb-avail';}
            return '<button type="button" class="'+cls+'" data-date="'+isoDate(d)+'" aria-label="'+esc(fmtFull(d,loc))+'"'+(sel?' aria-pressed="true"':'')+(ok?'':' disabled')+'>'+d.getDate()+'</button>';
          });
          h.push('<div class="wgb-cal-grid">'+cells.join('')+'</div>');
          h.push(tzMenu());
        }
      } else if(st.step==='time'){
        h.push(summary());
        h.push('<div class="uk-text-meta uk-text-bold uk-margin-small-bottom">'+esc(L.stepTime)+(cfg.duration?' · '+cfg.duration+' '+esc(L.minShort||'min'):'')+(cfg.location&&cfg.location!=='choice'?' · '+esc(locLabel()):'')+(cfg.price?' · '+esc(cfg.price.text):'')+'</div>');
        if(st.loadingBusy){h.push('<div class="uk-text-center uk-padding-small"><span uk-spinner></span></div>');}
        else{var sl=st.daySlots||[];
        if(!sl.length)h.push('<div class="uk-alert uk-alert-warning" role="status" uk-alert>'+esc(L.noSlots)+'</div>');
        else h.push('<div style="display:grid;grid-template-columns:repeat('+cols+',1fr);gap:6px">'+sl.map(function(t){return '<button type="button" class="'+slotBtn+' wgb-slot" data-time="'+esc(t)+'">'+esc(t)+'</button>';}).join('')+'</div>');}
        h.push(tzMenu());
      } else if(st.step==='form'){
        h.push(summary());
        h.push('<div class="uk-text-meta uk-text-bold uk-margin-small-bottom">'+esc(L.stepDetails)+'</div>');
        if(st.err)h.push('<div class="uk-alert uk-alert-danger" role="alert" uk-alert>'+esc(L.required)+'</div>');
        h.push('<div class="uk-margin-small"><input class="uk-input wgb-f-name" type="text" autocomplete="name" required aria-required="true" aria-label="'+esc(L.fName)+'" placeholder="'+esc(L.fName)+' *" value="'+esc(st.form.name)+'"></div>');
        h.push('<div class="uk-margin-small"><input class="uk-input wgb-f-email" type="email" autocomplete="email" required aria-required="true" aria-label="'+esc(L.fEmail)+'" placeholder="'+esc(L.fEmail)+' *" value="'+esc(st.form.email)+'"></div>');
        if(cfg.location==='choice'){
          h.push('<fieldset class="uk-fieldset uk-margin-small"><legend class="uk-text-meta uk-text-bold uk-margin-small-bottom">'+esc(L.locChoice||'')+'</legend>'
            +'<label class="uk-display-block"><input class="uk-radio wgb-f-loc" type="radio" name="wgb-loc" value="meet"'+(st.form.loc!=='phone'?' checked':'')+'> '+esc(L.locMeet||'')+'</label>'
            +'<label class="uk-display-block"><input class="uk-radio wgb-f-loc" type="radio" name="wgb-loc" value="phone"'+(st.form.loc==='phone'?' checked':'')+'> '+esc(L.locPhone||'')+'</label></fieldset>');
        }
        var needPhone=effLoc()==='phone',phLbl=needPhone?(L.fPhoneNeeded||L.fPhone):L.fPhone;
        h.push('<div class="uk-margin-small"><input class="uk-input wgb-f-phone" type="tel" autocomplete="tel"'+(needPhone?' required aria-required="true"':'')+' aria-label="'+esc(phLbl)+'" placeholder="'+esc(phLbl)+(needPhone?' *':'')+'" value="'+esc(st.form.phone)+'">'+(needPhone&&L.phoneHint?'<div class="uk-text-meta uk-margin-small-top wgb-phone-hint">'+esc(L.phoneHint)+'</div>':'')+'</div>');
        if(cfg.allowGuest)h.push('<div class="uk-margin-small"><input class="uk-input wgb-f-guest" type="email" autocomplete="off" aria-label="'+esc(L.fGuest)+'" placeholder="'+esc(L.fGuest)+'" value="'+esc(st.form.guest||'')+'"></div>');
        h.push('<div class="uk-margin-small"><textarea class="uk-textarea wgb-f-notes" rows="3" aria-label="'+esc(L.fNotes)+'" placeholder="'+esc(L.fNotes)+'">'+esc(st.form.notes)+'</textarea></div>');
        h.push(cfHtml());
        if(cfg.showNewsletter)h.push('<label class="uk-margin-small uk-display-block"><input class="uk-checkbox wgb-f-news" type="checkbox"'+(st.form.news?' checked':'')+'> '+esc(L.fNewsletter)+'</label>');
        h.push('<label class="uk-margin-small uk-display-block"><input class="uk-checkbox wgb-f-privacy" type="checkbox" aria-required="true"'+(st.form.privacy?' checked':'')+'> '+(L.privacyHtml||esc(L.privacy))+' <span class="wgb-req">*</span></label>');
        h.push('<input type="text" class="wgb-f-website" name="website" tabindex="-1" autocomplete="off" aria-hidden="true" style="position:absolute!important;left:-9999px;top:-9999px;height:1px;width:1px;opacity:0">');
        var enote=(effLoc()==='phone'&&L.emailNotePhone)?L.emailNotePhone:L.emailNote;if(enote)h.push('<div class="uk-text-meta uk-margin-small-top">'+esc(enote)+'</div>');
        if(cfg.price&&L.payNote)h.push('<div class="uk-text-meta uk-margin-small-top">'+esc(L.payNote.replace('%d',cfg.price.hold))+'</div>');
        h.push('<button type="button" class="uk-button uk-button-'+(cfg.btnStyle||'primary')+' uk-width-1-1 uk-margin-small-top" data-act="book">'+esc(cfg.price&&L.payNow?L.payNow.replace('%s',cfg.price.text):L.bookNow)+'</button>');
      } else if(st.step==='confirm'){
        h.push(summary());
        h.push('<div class="uk-text-meta uk-margin-small-bottom">'+esc(L.rsIntro||'')+'</div>');
        h.push('<button type="button" class="uk-button uk-button-'+(cfg.btnStyle||'primary')+' uk-width-1-1 uk-margin-small-top" data-act="rebook">'+esc(L.rsConfirm||'')+'</button>');
      } else {
        h.push('<div class="uk-alert uk-alert-success" uk-alert>'+esc(L.done)+'</div>');
      }
      mount.innerHTML=h.join('');
      focusAfter();
    }
    function focusAfter(){
      if(!st.focus)return;var f=st.focus;st.focus=null;var el=null;
      if(f==='prev'||f==='next')el=mount.querySelector('[data-act="'+f+'"]');
      else if(f==='form')el=mount.querySelector('.wgb-f-name');
      else if(f==='time')el=mount.querySelector('.wgb-slot')||mount.querySelector('.wgb-summary');
      else if(f==='back')el=mount.querySelector('.wgb-summary')||mount.querySelector('[data-act="prev"]')||mount.querySelector('.wgb-avail');
      else if(f==='confirm')el=mount.querySelector('[data-act="rebook"]')||mount.querySelector('.wgb-summary');
      if(!el)el=mount.querySelector('.wgb-summary')||mount.querySelector('button:not([disabled])');
      if(el&&el.focus){try{el.focus();}catch(e){}}
    }
    function readForm(){var q=function(c){var n=mount.querySelector('.'+c);return n?n.value:'';};var custom={};Array.prototype.forEach.call(mount.querySelectorAll('.wgb-cf'),function(n){var k=n.getAttribute('data-cf');if(!k)return;custom[k]=(n.type==='checkbox')?(n.checked?'1':'0'):(n.value||'').trim();});st.form={name:q('wgb-f-name').trim(),email:q('wgb-f-email').trim(),phone:q('wgb-f-phone').trim(),guest:q('wgb-f-guest').trim(),notes:q('wgb-f-notes').trim(),website:q('wgb-f-website'),news:!!(mount.querySelector('.wgb-f-news')||{}).checked,privacy:!!(mount.querySelector('.wgb-f-privacy')||{}).checked,custom:custom,loc:(function(){var r=mount.querySelector('.wgb-f-loc:checked');return r?r.value:(st.form.loc||'meet');})()};}
    mount.addEventListener('click',function(e){
      var t=e.target.closest('[data-act],[data-date],[data-time]');if(!t)return;
      if(t.hasAttribute('data-date')){var k=t.getAttribute('data-date');st.date=new Date(k+'T00:00:00');st.time=null;st.daySlots=(st.monthDays&&st.monthDays[k])?st.monthDays[k]:[];st.step='time';st.focus='time';}
      else if(t.hasAttribute('data-time')){st.time=t.getAttribute('data-time');st.err=false;if(cfg.mode==='reschedule'){st.step='confirm';st.focus='confirm';}else{st.step='form';st.focus='form';}}
      else{var a=t.getAttribute('data-act');
        if(a==='prev'){st.view=new Date(st.view.getFullYear(),st.view.getMonth()-1,1);st.focus='prev';}
        else if(a==='next'){st.view=new Date(st.view.getFullYear(),st.view.getMonth()+1,1);st.focus='next';}
        else if(a==='back'){if(st.step==='form'||st.step==='confirm'){if(st.step==='form')readForm();st.time=null;st.step='time';}else{st.date=null;st.step='cal';}st.focus='back';}
        else if(a==='book'){readForm();if(!st.form.name||!emailOk(st.form.email)||!st.form.privacy||cfMissing()||(effLoc()==='phone'&&!phoneOk(st.form.phone))){st.err=true;st.focus='form';}else{submit();return;}}
        else if(a==='rebook'){submitReschedule();return;}
        else if(a==='retry'){st.err=false;st.step='form';st.focus='form';}
        else if(a==='retrycal'){st.monthErr=null;st.calErr=null;st.monthKey=null;}
      }
      draw();
    });
    mount.addEventListener('change',function(e){var t=e.target;if(t&&t.classList&&t.classList.contains('wgb-tz-sel')){st.tz=tzOk(t.value)?t.value:st.tz;st.monthKey=null;st.monthErr=null;st.date=null;st.time=null;st.step='cal';st.slotMap={};st.view=todayLabel();st.focus=null;draw();var s2=mount.querySelector('.wgb-tz-sel');if(s2&&s2.focus){try{s2.focus();}catch(x){}}return;}var t=e.target;if(t&&t.classList&&t.classList.contains('wgb-f-loc')&&st.step==='form'){readForm();st.form.loc=t.value==='phone'?'phone':'meet';draw();}});
    draw();
  }
  function initOne(root){
    if(root.__wgbReady)return;root.__wgbReady=true;
    if(!root.querySelector('.wgb-mount')){var m=document.createElement('div');m.className='wgb-mount';root.appendChild(m);}
    render(root);
  }
  function initAll(ctx){var c=ctx||document;if(!c.querySelectorAll)return;Array.prototype.forEach.call(c.querySelectorAll('[data-wgb]'),initOne);}
  if(document.readyState!=='loading')initAll();else document.addEventListener('DOMContentLoaded',function(){initAll();});
  if(window.MutationObserver){new MutationObserver(function(muts){for(var i=0;i<muts.length;i++){var an=muts[i].addedNodes||[];for(var j=0;j<an.length;j++){var n=an[j];if(n&&n.nodeType===1){if(n.matches&&n.matches('[data-wgb]'))initOne(n);initAll(n);}}}}).observe(document.documentElement,{childList:true,subtree:true});}
})();
