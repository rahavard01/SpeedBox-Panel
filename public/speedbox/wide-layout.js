(function(){
  if(window.__SPEEDBOX_WIDE_MENUS__)return;
  window.__SPEEDBOX_WIDE_MENUS__=true;
  const selector='.account-actions-menu,.speedbox-partner-history-menu';
  let anchor=null;
  function position(menu,trigger){
    if(innerWidth<1367||!menu.isConnected||!trigger?.isConnected)return;
    const scale=innerWidth/1366,gap=6*scale,edge=12;
    const rect=trigger.getBoundingClientRect(),width=Math.min(205*scale,innerWidth-edge*2);
    menu.style.width=width+'px';menu.style.maxHeight='';
    const height=menu.getBoundingClientRect().height;
    const below=Math.max(0,innerHeight-rect.bottom-gap-edge),above=Math.max(0,rect.top-gap-edge);
    const upward=height>below&&above>below;
    menu.style.left=Math.max(edge,Math.min(rect.right-width,innerWidth-width-edge))+'px';
    menu.style.maxHeight=Math.max(0,upward?above:below)+'px';
    menu.style.top=upward?'auto':Math.max(edge,rect.bottom+gap)+'px';
    menu.style.bottom=upward?Math.max(edge,innerHeight-rect.top+gap)+'px':'auto';
  }
  function adjust(menu){
    const trigger=document.querySelector('.account-actions-trigger[aria-expanded="true"]')||anchor;
    requestAnimationFrame(()=>position(menu,trigger));
  }
  function start(){
    document.addEventListener('click',event=>{
      const button=event.target.closest?.('button');
      if(button&&!button.closest(selector))anchor=button;
    },true);
    const observer=new MutationObserver(records=>{
      for(const record of records)for(const node of record.addedNodes){
        if(node.nodeType!==1)continue;
        if(node.matches(selector))adjust(node);
        if(node.matches('.speedbox-partner-history')){
          observer.observe(node,{childList:true});node.querySelectorAll(selector).forEach(adjust);
        }
      }
    });
    // Menus are teleported under body; do not scan the application subtree.
    observer.observe(document.body,{childList:true});
    document.querySelectorAll('.speedbox-partner-history').forEach(node=>observer.observe(node,{childList:true}));
    window.addEventListener('resize',()=>document.querySelectorAll(selector).forEach(adjust));
  }
  if(document.readyState==='loading')document.addEventListener('DOMContentLoaded',start,{once:true});else start();
})();
