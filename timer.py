import threading
import sys
import time
from shutil import copyfile

print 'Number of arguments:', len(sys.argv), 'arguments.'
print 'Argument List:', str(sys.argv)

videoStart = int(sys.argv[1])
audioStart = int(sys.argv[2])
videoDuration = float(sys.argv[3])
audioDuration = float(sys.argv[4])
startTime = float(sys.argv[5])
sourcePath = sys.argv[6]
dstPath = sys.argv[7]

videoSegmentNum = videoStart
audioSegmentNum = audioStart

copyfile(sourcePath + '/video_8M_init.mp4', dstPath + '/video_8M_init.mp4')
copyfile(sourcePath + '/audio_64k_init.mp4', dstPath + '/audio_64k_init.mp4')

def videoProcess(videoDuration):
    global videoSegmentNum, startTime, videoStart
    
    filename = 'video_8M_' + str(videoSegmentNum) + '.mp4'
    copyfile(sourcePath + '/' + filename , dstPath + '/' + filename)
    
    nextSleep = (startTime + (videoSegmentNum - videoStart + 1)*videoDuration) - time.time() * 1000
    print 'video',videoDuration , ' Segment number', videoSegmentNum , ' Sleeping for ', nextSleep
    videoSegmentNum += 1
    
    if nextSleep/1000.0 > 0 :
    	threading.Timer(nextSleep/1000.0,videoProcess,[videoDuration]).start()
    else :
    	videoProcess(videoDuration)
    
def audioProcess(audioDuration):
    global audioSegmentNum, startTime, audioStart
    
    filename = 'audio_64k_' + str(audioSegmentNum) + '.mp4'
    copyfile(sourcePath + '/' + filename , dstPath + '/' + filename)
    
    nextSleep = (startTime + (audioSegmentNum - audioStart + 1)*audioDuration) - time.time() * 1000
    print 'audio',audioDuration , ' Segment number', audioSegmentNum , ' Sleeping for ' , nextSleep
    audioSegmentNum += 1 
    
    if nextSleep/1000.0 > 0 :
    	threading.Timer(nextSleep/1000.0,audioProcess,[audioDuration]).start()
    else :
		audioProcess(audioDuration)
		
videoProcess(videoDuration)
audioProcess(audioDuration)

open(dstPath + '/' + 'initialized.trig', 'a').close()