<?php
require_once(dirname(__FILE__) . "/lib.php");
//perform simulations to check the PCOC test performance

$nAUPcutoff = 0.05;
$sOutDIR = "pcoc_sim_out";
$sConvergeScenario = 'CTO,CMR,CSB,CHW/NFS,NKF,NOC,NOR,NRC,NVG,NVS,NFZ';
$arrRequiredTaxa = array("CTO" , "NOR");

//$sOutDIR = "pcoc_out_Aphy_Scriptaphy";
//$sConvergeScenario = 'SBT,SCV,SGG,SSC/AAU,ACG,ACL,ACM,ACY,AGM,AKM,APC,AKN1,AKN2';
//$arrRequiredTaxa = array("SCV" , "AAU");

$sTmp = 'tmp_sim/';

$sPremadeFasta = "../export_protein_multiref/ret_improved_cleanDNA.fasta";

$nThisPart = 0;
$nTotalPart = 1;

$sRootRscript = "reroot.R";


$sConstraintSpeciesPhylogenyDir = "/beegfs/group_dv/home/RCui/killifish_genomes/hyphyrelax/export_protein_multiref_23_july_2017/genetrees_improved"; 

$sCmdPrefix = 'LOCALUSR=`id -u $USER`; ';
$sCmdPrefix .= 'CMD_PCOC_DOCKER="docker run -e LOCAL_USER_ID=$LOCALUSR --rm -v $PWD:$PWD -e CWD=$PWD carinerey/pcoc"; $CMD_PCOC_DOCKER '; //for local nodes

$sCmdPrefix = 'shifter --image=index.docker.io/carinerey/pcoc:07022018 '; // for cluster
$sCmdTaxa2Num =$sCmdPrefix . 'python pcoc_taxa2nodenum.py';
$sCmdSim = $sCmdPrefix . 'pcoc_sim.py ';


while(count($argv) > 0) {
    $arg = array_shift($argv);
    switch($arg) {
        case '-p':
            $sPremadeFasta = trim(array_shift($argv));
            break;
        case '-m':
            $sProtModel = trim(array_shift($argv));
            break;
        case '-1':
            $sConstraintSpeciesPhylogenyDir = trim(array_shift($argv));
            break;
        case '-o':
            $sOutDIR  = trim(array_shift($argv));
            break;
        case '-N':
            $nTotalPart  = trim(array_shift($argv));
            break;
        case '-f':
            $nThisPart  = trim(array_shift($argv));
            break;
        case '-a':
            $nAUPcutoff  = trim(array_shift($argv));
            break;

    }
}

echo("Parsing constraint species phylogenies\n");
$arrConstraintSpeciesPhylo = fnParseAUOutput($sConstraintSpeciesPhylogenyDir);


fnExec("mkdir -p $sOutDIR");
$hPremadeFasta = fopen($sPremadeFasta , "r");
$sLn = "";

$sSeqName = "";
$sSeq = "";
$arrFullCodingSeq = array();
$sCurrentGene = "";
$nGeneCount = -1;

$sOutFile = "$sOutDIR/ret_part$nThisPart.of.$nTotalPart.txt";
//$arrComputedResults = fnLoadCurrentRet($sOutFile ); //load previous results, and redo items that failed or not done yet.
//print_r($arrComputedResults);

$hOut = popen("gzip > $sOutFile.gz" , 'w'); //append to it
//fwrite($hOut, "OrthoId\tAASite\tIndel_prop\tIndel_prop_ConvLeaves\tPCOC\tPC\tOC\tPath\n");
$bOutHeaderWritten = false;

do {
	$sLn = fgets($hPremadeFasta);

	if ($sLn==false) {
		fnProcessRecord($sSeqName, $sSeq);
		break;
	}

	$sLn = trim($sLn);
	if (substr($sLn , 0,1) == ">") {
		fnProcessRecord($sSeqName, $sSeq);
		$sSeqName = substr($sLn , 1);
		$sSeq = "";
		
	} else {
		$sSeq .= $sLn;
	}

} while (true);
fnProcessAln($sCurrentGene,$arrFullCodingSeq);


function fnProcessRecord($sSeqName, $sSeq) {
	global $arrFullCodingSeq , $sCurrentGene;
	if (strpos($sSeqName , "direction") !== false  ) {
		return; //don't process fragments
	}

	preg_match("/Ortho:(\S+);Sp:(\S+);MappedToRef:(\S+);SpGeneId:(\S+);GroupId:(\S+)/",  $sSeqName, $arrParsed);
/*
array(6
0	=>	Ortho:0;Sp:AAU;MappedToRef:AAU;SpGeneId:029799;GroupId:Group_0_0
1	=>	0
2	=>	AAU
3	=>	AAU
4	=>	029799
5	=>	Group_0_0
)

*/

	if (count($arrParsed)!=6 ) {
		return;
	}
	$sGeneName = $arrParsed[5];
	$sSpecies = $arrParsed[2];

	if ($sCurrentGene == $sGeneName ) {
		if (!array_key_exists($sSpecies , $arrFullCodingSeq)) {
			$arrFullCodingSeq[$sSpecies] = "";
		}

		$arrFullCodingSeq[$sSpecies] .= $sSeq;
	} else {
		if (count($arrFullCodingSeq) > 0) {
			fnProcessAln($sCurrentGene,$arrFullCodingSeq);
		}
		$arrFullCodingSeq = array();
		$sCurrentGene = $sGeneName;
		$arrFullCodingSeq[$sSpecies] = $sSeq;
	}

}


function fnProcessAln($sGeneName,$arrFullCodingSeq) {
	global $sOutDIR, $nThisPart, $nTotalPart, $nGeneCount,$hOut, $arrRequiredTaxa, $arrConstraintSpeciesPhylo, $nAUPcutoff, $sTmp, $sRootRscript , $sCmdTaxa2Num , $sConvergeScenario, $sCmdSim , $bOutHeaderWritten ;

	$nGeneCount++;
	if ($nGeneCount % $nTotalPart != $nThisPart) return;


		$arrFullCodingSeq = fnExcludeLastStop($arrFullCodingSeq);
		$arrAlnRet = fnTranslateAlignment($arrFullCodingSeq , false); //exclude last stop if exists.

		//Check if all sequences are identical, if so then no need to continue with codeml to save time
		$bDiff = false;
		$sTempSeq = -1;
		foreach($arrFullCodingSeq as $sTaxon => $sSeq) {
			if ($sTempSeq == -1) {
				$sTempSeq = $sSeq;
				continue;
			}
			if ($sTempSeq != $sSeq) {
				$bDiff = true;
				break;
			}
		}

		if (!$bDiff) {
			//fwrite($hOutTable, "$sGeneName\tAll sequences identical.".PHP_EOL);
			echo("$sGeneName : all sequences identical \n");
			fwrite($hOut , "$sGeneName\tAllSeqIdentical\n");
			return;
		}
			
		//remove taxa consist of only N's or gaps:
		$arrRemoveTaxa = array();
		foreach($arrFullCodingSeq as $sTaxon => $sSeq) {
			if ( preg_replace("/[N\-n]/", "", $sSeq) == '') {
				$arrRemoveTaxa[] = $sTaxon;
			}
		}

		foreach($arrRemoveTaxa as $sRemoveTaxon) {
			unset($arrFullCodingSeq[$sRemoveTaxon]);
		}


		if (!$arrAlnRet["stopcodon"] ) { //there is no stop codon, do raxml
			$sGroup = $sGeneName;


			if (!array_key_exists($sGroup , $arrConstraintSpeciesPhylo)) {
				return;
			}

			$arrSpeciesTreeInfo = $arrConstraintSpeciesPhylo[$sGroup];
			//only look at trees that have an accepted species tree phylogeny
			if ($arrSpeciesTreeInfo[4] < $nAUPcutoff) {
				echo("$sGroup has it's species phylogeny rejected, skip\n");
			}


			$bAllRequiredTaxaPresent = true;
			foreach($arrRequiredTaxa as $sReqTax) {
				if (strpos( $arrSpeciesTreeInfo[6] , $sReqTax) === false ){
					$bAllRequiredTaxaPresent = false;
					break;
				}
			}

			if (!$bAllRequiredTaxaPresent) {
				echo("$sGroup has key taxa missing, skip\n".$arrSpeciesTreeInfo[6]."\n");
				return;
			}

			$sSpTree = $arrSpeciesTreeInfo[6];


			$nSites = "";
			foreach($arrAlnRet['proteins'] as $sTaxon => $sSeq) {
				$nSites = strlen($sSeq);
				break;
			}


			#echo($sGeneName."\n");
			#echo($sSpTree."\n");
			#echo($sAln."\n=========================\n\n");

			$sThisTmp = $sTmp."/".mt_rand(10,99)."/".mt_rand(10,99)."/".mt_rand(10,99)."/".mt_rand(10,99);
			while(file_exists($sThisTmp)) {
				$sThisTmp = $sTmp."/".mt_rand(10,99)."/".mt_rand(10,99)."/".mt_rand(10,99)."/".mt_rand(10,99);
			}

			$sTreeTmpDir = "$sThisTmp/intree";
			$sTreeFile = $sThisTmp."/$sGeneName.tre";
			$sDetOutDir = "$sThisTmp/out";
			$sRootedTreeFile = $sTreeTmpDir."/$sGeneName.rooted.tre";


			fnExec("mkdir -p $sTreeTmpDir");

			file_put_contents($sTreeFile , $sSpTree);
			fnExec("Rscript $sRootRscript $sTreeFile $sRootedTreeFile");
			$sCmd = $sCmdTaxa2Num ." -t $sRootedTreeFile -L '$sConvergeScenario'";
			//echo($sCmd."\n");
			$sNumScenario = fnExec($sCmd );
			//ho($sNumScenario."\n");

			if (count(explode('/',$sNumScenario  )) != count(explode('/',$sConvergeScenario  )) ) {
				echo("Not enough taxa for $sGeneName\n");
				return;
			}

			//-td simtree/ -n_sites 802 --pcoc --ident --topo -m '47,41,42,43,44,45,46/23,11,12,13,14,15,16,17,18,19,20,21,22' -o simout -n_sc 100
			fnExec("cd $sThisTmp; $sCmdSim -td ". basename($sTreeTmpDir)." --ali_noise -n_sites $nSites  -n_sc 1 --pcoc --ident --topo -m '$sNumScenario' -o ". basename($sDetOutDir) );

			$arrFile = glob($sDetOutDir."/*/*/BenchmarkResults.tsv");
			if (count($arrFile) == 0) {
				echo("PCOC did not return result\n");
				return;
			}
			
			$h = fopen($arrFile[0] , 'r');
			while(false !== ($sLn = fgets($h)) ) {
				$sLn = trim($sLn);
				if ($sLn == '') continue;
				$arrF = explode("\t" , $sLn);
				$arrNeededFields = array_slice($arrF , 3);
				if (substr($sLn,0,5) == 'RunID') {
					if (!$bOutHeaderWritten) {
						//write header
						$arrHeader = array_merge( array('OrthoID') , $arrNeededFields, array('Path') );
						fwrite($hOut, implode("\t", $arrHeader)."\n");
						$bOutHeaderWritten = true;
					}

					continue;
				}
				$arrContent = array_merge(array($sGeneName), $arrNeededFields, array($arrFile[0]) );
				fwrite($hOut, implode("\t", $arrContent)."\n");
			}

			//die();
		}
		else {
			echo("$sGeneName : internal stop codon found, skip \n");
			fwrite($hOut , "$sGeneName\tStopFound\n");
			return;
		}
		


	
}


function fnExec($s) {
	echo($s."\n");
	return exec($s);
}

function fnParseAUOutput($sDir) {
	$hIn = popen("cat $sDir/ret*.txt" , 'r');

	$arrRet = array();
	while(false !== ($sLn = fgets($hIn))) {
		$sLn = trim($sLn);
		if ($sLn == '') continue;
		$arrF = explode("\t", $sLn);
		if (count($arrF) != 7) {
			//die("Reading fail: expected 7 fields\n$sLn\n");
			continue;
		}

		$arrRet[$arrF[0]] = $arrF;
	}

	return $arrRet;
}

?>
