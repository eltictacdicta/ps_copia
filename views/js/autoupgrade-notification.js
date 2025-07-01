/**
 * Copyright since 2007 PrestaShop SA and Contributors
 * PrestaShop is an International Registered Trademark & Property of PrestaShop SA
 *
 * NOTICE OF LICENSE
 *
 * This source file is subject to the Academic Free License version 3.0
 * that is bundled with this package in the file LICENSE.md.
 * It is also available through the world-wide-web at this URL:
 * https://opensource.org/licenses/AFL-3.0
 * If you did not receive a copy of the license and are unable to
 * obtain it through the world-wide-web, please send an email
 * to license@prestashop.com so we can send you a copy immediately.
 *
 * @author    PrestaShop SA and Contributors <contact@prestashop.com>
 * @copyright Since 2007 PrestaShop SA and Contributors
 * @license   https://opensource.org/licenses/AFL-3.0 Academic Free License version 3.0
 */
var t,e,s,a,i,n,o,r,d,m,c=t=>{throw TypeError(t)},l=(t,e,s)=>e.has(t)||c("Cannot "+s),h=(t,e,s)=>(l(t,e,"read from private field"),s?s.call(t):e.get(t)),u=(t,e,s)=>e.has(t)?c("Cannot add the same private member more than once"):e instanceof WeakSet?e.add(t):e.set(t,s),p=(t,e,s)=>(l(t,e,"access private method"),s);import{a as f,b as w}from"../assets/SegmentApi-CpPZ-MB1.js";import"../assets/segment-analytics-next-CX5zjpmb.js";import"../assets/segment-analytics-core-Cvc9gLK6.js";import"../assets/segment-analytics-generic-utils-EvPM-36Q.js";import"../assets/segment-facade-BI66Bou3.js";import"../assets/segment-isodate-DZqlg9qm.js";import"../assets/segment-isodate-traverse-DdrIA5-2.js";const g=f.create({baseURL:`${window.AutoUpgradeVariables.admin_url}/index.php?controller=AdminAutoupgradeAjax`,headers:{"X-Requested-With":"XMLHttpRequest"},params:{ajax:1,token:window.AutoUpgradeVariables.token}}),v=w;t=new WeakMap,e=new WeakMap,s=new WeakMap,a=new WeakSet,i=function(){const e=document.getElementById(h(this,t));return e instanceof HTMLDialogElement?e:null},n=function(t,e=["action"]){const s=document.forms.namedItem(t);if(!s)throw new Error("Form not found");return e.forEach((t=>{if(!s.dataset[t])throw new Error(`Missing data ${t} from form dataset.`)})),s},o=function(){return p(this,a,n).call(this,h(this,e))},r=function(){return p(this,a,n).call(this,h(this,s))},d=new WeakMap,m=new WeakMap;(new class{constructor(){u(this,a),u(this,t,"dialog-update-notification"),u(this,e,"remind-me-later-update"),u(this,s,"submit-update"),u(this,d,(async t=>{t.preventDefault();const e=t.target,s=t.submitter;"dialog"===s.dataset.dismiss&&h(this,a,i).close();try{const t={action:e.dataset.action};s.value?(Object.assign(t,{value:s.value}),await v.track("[SUE] Update modal snoozed",{representation_delay:s.value})):await v.track("[SUE] Update module opened following modal display");const a=await g.post("",null,{params:t});a.data.url_to_redirect&&(window.location=a.data.url_to_redirect),this.beforeDestroy()}catch(n){console.error(n)}})),u(this,m,(t=>{t.preventDefault()}))}mount(){h(this,a,i)&&(v.track("[SUE] Update modal displayed"),h(this,a,i).showModal(),h(this,a,i).addEventListener("close",h(this,m)),h(this,a,o).addEventListener("submit",h(this,d)),h(this,a,r).addEventListener("submit",h(this,d)))}beforeDestroy(){h(this,a,i)&&(h(this,a,i).removeEventListener("close",h(this,m)),h(this,a,o).removeEventListener("submit",h(this,d)),h(this,a,r).removeEventListener("submit",h(this,d)))}}).mount();
