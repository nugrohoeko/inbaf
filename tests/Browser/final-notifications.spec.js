import {test,expect} from '@playwright/test';
import fs from 'node:fs';
import {login,noHorizontalOverflow} from './helpers.js';

test('notification bell loads owned exam activity and follows real resume and result links on mobile',async({page})=>{
    await page.addInitScript(()=>localStorage.setItem('inbaf-language','en'));
    await login(page);
    const packages=(await(await page.request.get('/api/packages?test_type=practice')).json()).packages;
    const selected=packages.find(row=>row.code==='INBAF-DAILY');
    expect(selected).toBeTruthy();
    const started=await page.evaluate(async id=>{
        const response=await fetch('/api/packages/'+id+'/attempts',{method:'POST',headers:{Accept:'application/json','Content-Type':'application/json','X-CSRF-TOKEN':document.querySelector('meta[name="csrf-token"]').content},body:'{}'});
        return {status:response.status,data:await response.json()};
    },selected.id);
    expect(started.status).toBe(201);
    await page.setViewportSize({width:320,height:820});
    const feed=page.waitForResponse(response=>response.url().endsWith('/api/notifications'));
    await page.getByRole('button',{name:'Notifications',exact:true}).click();
    expect((await feed).status()).toBe(200);
    const panel=page.locator('.notification-popover');
    const resume=panel.getByRole('link',{name:new RegExp('Resume assessment.*'+selected.name.replace(/[.*+?^${}()|[\]\\]/g,'\\$&'))});
    await expect(resume).toHaveAttribute('href','/exam/'+started.data.attempt.id);
    for(const theme of ['light','dark']){
        await page.evaluate(value=>document.documentElement.classList.toggle('dark',value==='dark'),theme);
        await noHorizontalOverflow(page);
        const bounds=await panel.boundingBox();
        expect(bounds.x).toBeGreaterThanOrEqual(0);expect(bounds.x+bounds.width).toBeLessThanOrEqual(320);
        fs.mkdirSync('tmp/qa/final/screenshots',{recursive:true});
        await panel.screenshot({path:'tmp/qa/final/screenshots/notifications-'+theme+'-320.png'});
    }
    await resume.click();
    await expect(page).toHaveURL('/exam/'+started.data.attempt.id);
    const submitted=await page.evaluate(async id=>{
        const response=await fetch('/api/attempts/'+id+'/submit',{method:'POST',headers:{Accept:'application/json','Content-Type':'application/json','X-CSRF-TOKEN':document.querySelector('meta[name="csrf-token"]').content},body:'{}'});
        return {status:response.status,data:await response.json()};
    },started.data.attempt.id);
    expect(submitted.status).toBe(200);expect(submitted.data.attempt.status).toBe('completed');
    await page.goto('/dashboard');
    await page.getByRole('button',{name:'Notifications',exact:true}).click();
    const result=page.locator('.notification-popover').getByRole('link',{name:new RegExp('Assessment result available.*'+selected.name.replace(/[.*+?^${}()|[\]\\]/g,'\\$&'))}).first();
    await expect(result).toHaveAttribute('href','/results/'+started.data.attempt.id);
    await result.click();
    await expect(page).toHaveURL('/results/'+started.data.attempt.id);
});
