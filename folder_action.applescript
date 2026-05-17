on adding folder items to this_folder after receiving added_items
	set scriptPath to "/Users/isidrojosesuarezrodriguez/Desktop/Contratos/folder_action.sh"
	
	repeat with anItem in added_items
		set itemPath to POSIX path of anItem
		do shell script quoted form of scriptPath & space & quoted form of itemPath
	end repeat
end adding folder items to
