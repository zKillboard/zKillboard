function startclock()
{

var thetime=new Date();
var nhours=thetime.getUTCHours();
var nmins=thetime.getMinutes();
var nmins = (thetime.getMinutes() < 10 ? '0' : '') + thetime.getMinutes();
 
var clock_span = document.getElementById("my_clock");
clock_span.innerHTML = nhours+":"+nmins+" UTC";

setTimeout('startclock()',1000);
} 

if (document.getElementById && document.createTextNode) {
  startclock();
}
