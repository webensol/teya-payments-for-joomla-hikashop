const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const vm = require('node:vm');
const source = fs.readFileSync(path.join(__dirname,'../plugins/hikashoppayment/teya/assets/teya-refund.js'),'utf8');
async function test(locale) {
  const ini = fs.readFileSync(path.join(__dirname,`../plugins/hikashoppayment/teya/language/${locale}/${locale}.plg_hikashoppayment_teya.ini`),'utf8');
  const strings = Object.fromEntries([...ini.matchAll(/^PLG_HIKASHOPPAYMENT_TEYA_([A-Z_]+)="(.*)"$/gm)].map(match=>[match[1],match[2]]));
  let ready, confirmed = true, failed = false, reloads = 0;
  const handlers = {}, calls = [], prompts = [], timers = [];
  const button = {disabled:false,dataset:{action:'refund'},addEventListener(event,handler){handlers[event]=handler;}};
  const output = {textContent:''};
  const panel = {dataset:{messages:JSON.stringify(strings),url:'/administrator/refund',order:'12',key:'fixture-refund-key',token:'csrf'},querySelectorAll(){return [button];},querySelector(selector){return selector==='.teya-refund-result' ? output : {value:' 2.00 '};}};
  const context = {
    document:{addEventListener(event,handler){ready=handler;},querySelectorAll(){return [panel];}},
    window:{confirm(message){prompts.push(message);return confirmed;},setTimeout(handler){timers.push(handler);},location:{reload(){reloads++;}}},
    fetch:async(url,options)=>{calls.push(options);return {ok:true,json:async()=>{if(failed)throw new Error('PRIVATE_HTML_RESPONSE');return {pending:false};}};},
    URLSearchParams,JSON,Error
  };
  vm.runInNewContext(source,context); ready();
  confirmed=false; await handlers.click(); assert.equal(calls.length,0);
  confirmed=true; await handlers.click();
  assert.equal(prompts.at(-1),strings.REFUND_CONFIRM);
  assert.equal(calls[0].body.get('csrf'),'1'); assert.equal(calls[0].body.get('amount'),'2.00');
  assert.equal(output.textContent,strings.REFUND_CONFIRMED);
  await handlers.click(); assert.equal(calls.length,1,'disabled button prevents duplicate submission');
  timers.shift()(); assert.equal(reloads,1);
  button.disabled=false; button.dataset.action='refund_retry'; failed=true;
  await handlers.click();
  assert.equal(prompts.at(-1),strings.REFUND_CONFIRM_RETRY);
  assert.equal(calls.at(-1).body.get('request_key'),'fixture-refund-key');
  assert.equal(output.textContent,strings.REFUND_ERROR+' '+strings.REFUND_REFRESH);
  assert(!output.textContent.includes('PRIVATE_HTML_RESPONSE'),'JSON parser/provider errors must not leak');
}
(async()=>{for(const locale of ['en-GB','hu-HU','cs-CZ','sk-SK','de-DE'])await test(locale);console.log('PASS: five-language refund confirmation/success/error UI, CSRF, stable retry reference and double-click protection');})().catch(error=>{console.error(error);process.exitCode=1;});
