/*
 * Hand-written stand-in for the OnlyFans signing webpack chunk. Same calling
 * shape as the real one (webpackChunkof_vue push, module(t) with t.n getters
 * for SHA-1 and lodash-get, export A({url}) -> {time, sign}), light string
 * obfuscation, KNOWN constants so the extractor can be checked exactly:
 *   static_param     SyntheticStaticParam0123456789ab
 *   prefix / suffix  12345 / abcd1234
 *   indexes          [1, 1, 5, 9, 22, 39]
 *   constant         -77
 * No OnlyFans code is committed; this file is original test data.
 */
!function(){try{var W="undefined"!=typeof window?window:globalThis;W.SENTRY_RELEASE={id:"202609010000-0123456789"}}catch(W){}}(),
(self.webpackChunkof_vue=self.webpackChunkof_vue||[]).push([[9999],{
    100001:function(m,e,t){m.exports={A:function(){return{unrelated:!0}}}},
    109999:function(m,e,t){
        var s=t(1),h=t.n(s),g=t(2),get=t.n(g),store=t(3);
        var k=["U3ludGhldGljU3RhdGljUGFyYW0wMTIzNDU2Nzg5YWI=","MTIzNDU=","YWJjZDEyMzQ="];
        function d(i){return decodeURIComponent(escape(atob(k[i])))}
        function atob(b){var c="ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789+/",o="",n=0,v=0,l=0;for(var i=0;i<b.length;i++){var x=c.indexOf(b.charAt(i));if(x<0)continue;v=(v<<6)|x;l+=6;if(l>=8){l-=8;o+=String.fromCharCode((v>>l)&255)}}return o}
        e.A=function(r){
            var url=get()(r,"url",""),ua=get()(W(),"navigator.userAgent",null),uid=get()(store.A,"getters.auth/authUserId",null)||0;
            var time=Date.now(),sha=h()([d(0),time,url,uid].join("\n"));
            var sum=[1,1,5,9,22,39].reduce(function(a,i){return a+sha[i].charCodeAt(0)},0)-77;
            return{time:time,sign:[d(1),sha,Math.abs(sum).toString(16),d(2)].join(":")};
        };
        function W(){return"undefined"!=typeof window?window:{}}
    }
}]);
