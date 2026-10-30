(function(){
  if (typeof aniChartData === 'undefined' || typeof Chart === 'undefined') return;
  const histCtx = document.getElementById('aniHistogram');
  if (histCtx) new Chart(histCtx, {type:'bar',data:{labels:aniChartData.histLabels,datasets:[{label:'Active plans by amount',data:aniChartData.histCounts}]},options:{responsive:true,plugins:{legend:{display:false}},scales:{x:{ticks:{autoSkip:true,maxRotation:0}},y:{beginAtZero:true}}}});
  const pieCtx = document.getElementById('aniIntervalPie');
  if (pieCtx) { const ic = aniChartData.intervalCounts||{weekly:0,monthly:0,annual:0,other:0}; new Chart(pieCtx,{type:'pie',data:{labels:['Weekly','Monthly','Annual','Other'],datasets:[{data:[ic.weekly||0,ic.monthly||0,ic.annual||0,ic.other||0]}]},options:{responsive:true}}); }
  const segCtx = document.getElementById('aniSegmentsBar');
  if (segCtx) { const seg=aniChartData.segments||{}; new Chart(segCtx,{type:'bar',data:{labels:Object.keys(seg),datasets:[{label:'Subscriptions',data:Object.values(seg)}]},options:{responsive:true,plugins:{legend:{display:false}},scales:{y:{beginAtZero:true}}}}); }
  const sparkCtx = document.getElementById('aniSparkline');
  if (sparkCtx) { const labels=(aniChartData.spark&&aniChartData.spark.labels)?aniChartData.spark.labels.slice(-30):[]; const active=(aniChartData.spark&&aniChartData.spark.active)?aniChartData.spark.active.slice(-30):[]; new Chart(sparkCtx,{type:'line',data:{labels,datasets:[{label:'Active',data:active,tension:0.3}]},options:{responsive:true,plugins:{legend:{display:false}},scales:{x:{display:false},y:{beginAtZero:true,display:true}},elements:{point:{radius:0}}}); }
})();