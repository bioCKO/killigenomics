
sPop1=RACWET.excludehybrid
sPop2=ORTDRY.excludehybrid

sOutDIR=${sPop1}_${sPop2}
mkdir $sOutDIR
hhvm computeFST.php $sPop1 $sPop2 $sOutDIR > $sOutDIR/fst.log 2>&1 
