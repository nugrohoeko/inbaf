/** Wrap chart labels to a measured width, including words without spaces. */
export function wrapChartLabel(value,maxWidth,measure){
    const rows=[];let row='';
    const words=String(value??'').trim().split(/\s+/).filter(Boolean);
    for(const word of words){
        if(row&&measure(row+' '+word)<=maxWidth){row+=' '+word;continue}
        if(row){rows.push(row);row=''}
        for(const character of word){
            if(row&&measure(row+character)>maxWidth){rows.push(row);row=character}else row+=character;
        }
    }
    if(row)rows.push(row);
    return rows;
}

// Stay below Chart.js' half-canvas axis limit and leave space for the plot.
export function chartLabelWidth(canvasWidth){return Math.max(48,Math.min(176,(canvasWidth-32)*.4))}
